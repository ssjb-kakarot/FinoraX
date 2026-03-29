<?php
require 'auth.php'; // Ensures $_SESSION['user_id'] is set
require 'config.php'; // Contains $GEMINI_API_KEY array

$user_id = $_SESSION['user_id'];

// =========================================================================
// ONBOARDING COMPLETION CHECK (AUTO-REDIRECT)
// =========================================================================
// If a profile record already exists for this user, they have finished onboarding.
$checkStmt = $pdo->prepare("SELECT id FROM financial_profiles WHERE user_id = ?");
$checkStmt->execute([$user_id]);
if ($checkStmt->fetch()) {
    header("Location: dashboard.php");
    exit();
}

// Fetch User's Name to display in the UI
$stmt = $pdo->prepare("SELECT name FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$userRow = $stmt->fetch();
$userName = $userRow ? explode(' ', trim($userRow['name']))[0] : 'User'; // Get First Name

// =========================================================================
// BACKEND LOGIC (AJAX Handlers)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    function sendJsonResponse($data) {
        ob_clean(); 
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    // ---------------------------------------------------------
    // 1. AI PDF UPLOAD & EXTRACTION HANDLER
    // ---------------------------------------------------------
    if (isset($_POST['action']) && $_POST['action'] === 'upload_statement' && isset($_FILES['statements'])) {
        ob_start();
        error_reporting(E_ALL);
        ini_set('display_errors', 0);
        ini_set('memory_limit', '512M');
        set_time_limit(300); 

        if (isset($GEMINI_API_KEY) && is_string($GEMINI_API_KEY)) $GEMINI_API_KEY = [$GEMINI_API_KEY];

        if (empty($GEMINI_API_KEY) || !is_array($GEMINI_API_KEY)) {
            sendJsonResponse(['error' => 'Gemini API Key missing. Check config.php']);
        }

        if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
            sendJsonResponse(['error' => 'Dependencies missing. Please run "composer require smalot/pdfparser"']);
        }

        require __DIR__ . '/vendor/autoload.php';

        $parser = new \Smalot\PdfParser\Parser();
        $rawTextCombo = "";

        foreach ($_FILES['statements']['tmp_name'] as $index => $tmpName) {
            if ($_FILES['statements']['error'][$index] !== UPLOAD_ERR_OK) continue;
            try {
                $pdf = $parser->parseFile($tmpName);
                $text = $pdf->getText();
                $text = preg_replace("/\n\s*\n/", "\n", $text); 
                $rawTextCombo .= $text . "\n\n";
            } catch (Exception $e) {
                sendJsonResponse(['error' => 'Failed to read PDF: ' . $e->getMessage()]);
            }
        }

        if (empty(trim($rawTextCombo))) sendJsonResponse(['error' => 'Could not extract text from PDF.']);

        $maxChunkSize = 35000;
        $lines = explode("\n", $rawTextCombo);
        $textChunks = [];
        $currentChunk = "";

        foreach ($lines as $line) {
            if (strlen($currentChunk) + strlen($line) > $maxChunkSize) {
                $textChunks[] = $currentChunk;
                $currentChunk = $line . "\n";
            } else {
                $currentChunk .= $line . "\n";
            }
        }
        if (!empty(trim($currentChunk))) $textChunks[] = $currentChunk;

        $allTransactions = [];

        foreach ($textChunks as $chunkIndex => $chunk) {
            $prompt = "
            You are a highly accurate financial data extraction AI. 
            Extract ALL successful transactions from this bank statement chunk and return ONLY a valid JSON array of objects.
            Do NOT include any markdown formatting like ```json. Start directly with [ and end with ].
            If there are no transactions, return [].
            
            Rules:
            1. Clean up 'description' (remove UPI IDs, UTR numbers, filler words).
            2. Categorize into EXACTLY ONE of: 'Rent / EMI', 'Utilities & Bills', 'Food & Groceries', 'Transport', 'Shopping & Lifestyle', 'Healthcare', 'Education', 'Entertainment', 'Income', 'Other Expenses'.
            3. Amount MUST be a raw number (e.g. 150.50).
            
            Format EXACTLY:
            [{\"date\": \"YYYY-MM-DD HH:MM\", \"description\": \"Name\", \"type\": \"CREDIT\" or \"DEBIT\", \"amount\": 150.50, \"category\": \"Food & Groceries\", \"platform\": \"PhonePe\"}]

            Raw Chunk: " . $chunk;

            $postData = ["contents" => [["parts" => [["text" => $prompt]]]], "generationConfig" => ["temperature" => 0.1]];
            $jsonData = json_encode($postData);

            $aiText = null;
            $success = false;

            foreach ($GEMINI_API_KEY as $key) {
                $apiUrl = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=' . trim($key);
                $ch = curl_init($apiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 90); 

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($response !== false) {
                    $responseData = json_decode($response, true);
                    if ($httpCode === 200 && !isset($responseData['error']) && isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                        $aiText = $responseData['candidates'][0]['content']['parts'][0]['text'];
                        $success = true; break;
                    }
                }
            }

            if (!$success || $aiText === null) continue;

            if (preg_match('/\[.*\]/s', $aiText, $matches)) $jsonString = $matches[0];
            else $jsonString = $aiText; 

            $jsonString = str_replace(['```json', '```'], '', $jsonString);
            $transactions = json_decode(trim($jsonString), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($transactions)) {
                $allTransactions = array_merge($allTransactions, $transactions);
            }
        }

        if (empty($allTransactions)) sendJsonResponse(['error' => 'AI failed to extract valid transactions.']);

        // -------------------------------------------------------------
        // DATE FILTERING LOGIC (LATEST MONTH + PREVIOUS MONTH ONLY)
        // -------------------------------------------------------------
        $maxTimestamp = 0;
        foreach($allTransactions as $tx) {
            $ts = strtotime($tx['date'] ?? 0);
            if($ts > $maxTimestamp) $maxTimestamp = $ts;
        }

        $totalExtractedCount = count($allTransactions);

        if ($maxTimestamp > 0) {
            $latestMonth = date('Y-m', $maxTimestamp);
            $prevMonth = date('Y-m', strtotime('-1 month', $maxTimestamp));
            
            $allTransactions = array_filter($allTransactions, function($tx) use ($latestMonth, $prevMonth) {
                $txMonth = date('Y-m', strtotime($tx['date']));
                return ($txMonth === $latestMonth || $txMonth === $prevMonth);
            });
            $allTransactions = array_values($allTransactions); // Re-index array
        }

        // Sort Descending
        usort($allTransactions, function($a, $b) {
            return strtotime($b['date'] ?? 0) - strtotime($a['date'] ?? 0);
        });

        // SAVE FILTERED TRANSACTIONS TO DATABASE
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("DELETE FROM transactions WHERE user_id = ?");
            $stmt->execute([$user_id]);

            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, date, description, type, amount, category, platform) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($allTransactions as $tx) {
                $date = !empty($tx['date']) ? date('Y-m-d H:i:s', strtotime($tx['date'])) : null;
                $stmt->execute([
                    $user_id,
                    $date,
                    substr($tx['description'] ?? 'Unknown', 0, 250),
                    $tx['type'] ?? 'DEBIT',
                    (float)($tx['amount'] ?? 0),
                    $tx['category'] ?? 'Other Expenses',
                    $tx['platform'] ?? ''
                ]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            sendJsonResponse(['error' => 'Database insertion failed: ' . $e->getMessage()]);
        }

        sendJsonResponse([
            'success' => true, 
            'data' => $allTransactions, 
            'meta' => [
                'total_found' => $totalExtractedCount,
                'filtered_count' => count($allTransactions)
            ]
        ]);
    }

    // ---------------------------------------------------------
    // 2. FINAL FORM SUBMISSION HANDLER
    // ---------------------------------------------------------
    if (isset($_POST['action']) && $_POST['action'] === 'submit_onboarding') {
        
        $monthly_income = (float)($_POST['monthly_income'] ?? 0);
        $other_income   = (float)($_POST['other_income'] ?? 0);
        $total_income   = $monthly_income + $other_income;

        $rent       = (float)($_POST['rent_emi'] ?? 0);
        $utilities  = (float)($_POST['utilities'] ?? 0);
        $food       = (float)($_POST['food'] ?? 0);
        $transport  = (float)($_POST['transport'] ?? 0);
        $lifestyle  = (float)($_POST['lifestyle'] ?? 0);
        $healthcare = (float)($_POST['healthcare'] ?? 0);
        $education  = (float)($_POST['education'] ?? 0);
        $entertain  = (float)($_POST['entertainment'] ?? 0);
        $other_exp  = (float)($_POST['other_expenses'] ?? 0);
        
        $total_expenses = $rent + $utilities + $food + $transport + $lifestyle + $healthcare + $education + $entertain + $other_exp;

        $savings_rate = $total_income > 0 ? (($total_income - $total_expenses) / $total_income) * 100 : 0;
        $savings_rate = max(0, min(100, $savings_rate));

        try {
            $sql = "INSERT INTO financial_profiles 
                (user_id, age, employment_type, monthly_income, other_income, rent_emi, utilities, food, transport, lifestyle, healthcare, education, entertainment, other_expenses, total_expenses, savings_rate, savings, investments, debt, insurance, goal_name, target_amount, goal_years, risk_tolerance, track_expenses, invest_regularly, emergency_fund, primary_concern, autopilot) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                age=VALUES(age), employment_type=VALUES(employment_type), monthly_income=VALUES(monthly_income), other_income=VALUES(other_income), rent_emi=VALUES(rent_emi), utilities=VALUES(utilities), food=VALUES(food), transport=VALUES(transport), lifestyle=VALUES(lifestyle), healthcare=VALUES(healthcare), education=VALUES(education), entertainment=VALUES(entertainment), other_expenses=VALUES(other_expenses), total_expenses=VALUES(total_expenses), savings_rate=VALUES(savings_rate), savings=VALUES(savings), investments=VALUES(investments), debt=VALUES(debt), insurance=VALUES(insurance), goal_name=VALUES(goal_name), target_amount=VALUES(target_amount), goal_years=VALUES(goal_years), risk_tolerance=VALUES(risk_tolerance), track_expenses=VALUES(track_expenses), invest_regularly=VALUES(invest_regularly), emergency_fund=VALUES(emergency_fund), primary_concern=VALUES(primary_concern), autopilot=VALUES(autopilot)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $user_id, (int)$_POST['age'], $_POST['employment_type'], $monthly_income, $other_income,
                $rent, $utilities, $food, $transport, $lifestyle, $healthcare, $education, $entertain, $other_exp,
                $total_expenses, $savings_rate,
                (float)($_POST['savings'] ?? 0), (float)($_POST['investments'] ?? 0), (float)($_POST['debt'] ?? 0), (float)($_POST['insurance'] ?? 0),
                $_POST['goal_name'] ?? '', (float)($_POST['target_amount'] ?? 0), (int)($_POST['goal_years'] ?? 0),
                $_POST['risk_tolerance'] ?? 'Medium', isset($_POST['track_expenses']) ? 1 : 0, isset($_POST['invest_regularly']) ? 1 : 0, isset($_POST['emergency_fund']) ? 1 : 0,
                $_POST['primary_concern'] ?? '', isset($_POST['autopilot']) ? 1 : 0
            ]);

            sendJsonResponse(['success' => true]);
        } catch (Exception $e) {
            sendJsonResponse(['error' => 'Save failed: ' . $e->getMessage()]);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Financial Setup | Finora X</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    
    <style>
        :root { --primary: #0a2540; --accent: #c9a962; }
        body { font-family: 'DM Sans', sans-serif; background-color: #f8fafc; min-height: 100vh; overflow-x: hidden; }
        .heading-font { font-family: 'Playfair Display', serif; }
        
        .step-container { display: none; opacity: 0; transform: translateX(20px); transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .step-container.active { display: block; opacity: 1; transform: translateX(0); }
        
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-icon { position: absolute; left: 1rem; color: #94a3b8; width: 1.25rem; height: 1.25rem; transition: color 0.3s; }
        .input-field { background: #ffffff; border: 1.5px solid #e2e8f0; padding: 0.875rem 1rem 0.875rem 2.75rem; border-radius: 0.75rem; width: 100%; outline: none; transition: all 0.3s; font-weight: 500; color: #1e293b; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .input-field:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(10,37,64,0.08); }
        .input-field:focus + .input-icon, .input-wrapper:focus-within .input-icon { color: var(--primary); }
        .input-label { display: block; font-size: 0.875rem; font-weight: 600; color: #475569; margin-bottom: 0.5rem; }

        .loader { border-top-color: var(--primary); animation: spinner 1s cubic-bezier(0.4, 0, 0.2, 1) infinite; }
        @keyframes spinner { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .toggle-checkbox:checked { right: 0; border-color: #10b981; }
        .toggle-checkbox:checked + .toggle-label { background-color: #10b981; }
        .toggle-checkbox { right: 0; z-index: 1; border-color: #e2e8f0; transition: all 0.3s ease; }
        .toggle-label { width: 3.25rem; height: 1.75rem; background-color: #cbd5e1; border-radius: 9999px; transition: all 0.3s ease; }

        /* Highlight animation for AI autofill */
        @keyframes successPulse {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); border-color: #10b981; }
            70% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); border-color: #10b981; }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); border-color: #e2e8f0; }
        }
        .ai-filled { animation: successPulse 2s ease-out; background-color: #f0fdf4 !important; }
    </style>
</head>
<body class="flex flex-col items-center py-6 px-4 sm:py-10">

    <!-- Header -->
    <div class="w-full max-w-4xl flex justify-between items-center mb-6 sm:mb-10 px-2">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 sm:w-12 sm:h-12 bg-[#0A2540] rounded-xl flex items-center justify-center shadow-lg">
                <i data-lucide="landmark" class="w-5 h-5 sm:w-6 sm:h-6 text-white"></i>
            </div>
            <div>
                <h1 class="heading-font text-xl sm:text-2xl font-bold text-slate-900 leading-tight">Finora X</h1>
                <p class="text-xs sm:text-sm font-medium text-slate-500">Welcome, <span class="text-[#c9a962] font-bold"><?= htmlspecialchars($userName) ?></span></p>
            </div>
        </div>
        
        <!-- Mobile Progress Text -->
        <div class="sm:hidden bg-white px-4 py-2 rounded-full shadow-sm border border-slate-100 font-bold text-sm text-[#0A2540]">
            Step <span id="mobile-step">1</span> of 6
        </div>
    </div>

    <!-- Main Card -->
    <div class="w-full max-w-4xl bg-white rounded-3xl shadow-xl border border-slate-100 overflow-hidden relative">
        
        <!-- Desktop Progress Bar -->
        <div class="hidden sm:block bg-slate-50 border-b border-slate-100 px-10 py-8 relative">
            <div class="flex justify-between items-center relative z-10">
                <div class="absolute left-0 top-1/2 -translate-y-1/2 w-full h-1.5 bg-slate-200 rounded-full z-0"></div>
                <div id="progress-fill" class="absolute left-0 top-1/2 -translate-y-1/2 h-1.5 bg-[#0A2540] rounded-full z-0 transition-all duration-700 ease-out" style="width: 0%"></div>
                
                <!-- Steps -->
                <script>
                    for(let i=1; i<=6; i++) {
                        document.write(`<div class="step-indicator w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm bg-slate-200 text-slate-500 ring-4 ring-slate-50 transition-colors duration-500 relative z-10" id="ind-${i}">${i}</div>`);
                    }
                </script>
            </div>
            <div class="text-center mt-6">
                <p class="text-xs font-bold tracking-widest text-[#c9a962] uppercase mb-1">Onboarding Process</p>
                <h2 id="step-title" class="font-bold text-slate-800 text-2xl">Basic Info</h2>
            </div>
        </div>

        <form id="onboardingForm" class="p-6 sm:p-10">
            <input type="hidden" name="action" value="submit_onboarding">

            <!-- STEP 1: Basic Info -->
            <div class="step-container active" id="step-1">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 sm:gap-8">
                    <div>
                        <label class="input-label">Your Age</label>
                        <div class="input-wrapper">
                            <i data-lucide="calendar" class="input-icon"></i>
                            <input type="number" name="age" min="18" max="100" class="input-field" placeholder="e.g. 28" required>
                        </div>
                    </div>
                    <div>
                        <label class="input-label">Employment Type</label>
                        <div class="input-wrapper">
                            <i data-lucide="briefcase" class="input-icon"></i>
                            <select name="employment_type" class="input-field" required>
                                <option value="" disabled selected>Select Option</option>
                                <option value="Salaried">Salaried Employee</option>
                                <option value="Self-Employed">Freelancer / Self-Employed</option>
                                <option value="Business">Business Owner</option>
                                <option value="Student">Student</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- STEP 2: Income -->
            <div class="step-container" id="step-2">
                <div class="bg-blue-50/50 border border-blue-100 rounded-2xl p-5 mb-8 flex items-start gap-4">
                    <i data-lucide="info" class="w-6 h-6 text-blue-500 flex-shrink-0 mt-0.5"></i>
                    <p class="text-sm text-blue-800 leading-relaxed">Let's build your income profile. Include your standard take-home pay and any side hustles. This helps us calculate your true savings rate.</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 sm:gap-8">
                    <div>
                        <label class="input-label">Primary Monthly Income (₹)</label>
                        <div class="input-wrapper">
                            <i data-lucide="wallet" class="input-icon"></i>
                            <input type="number" name="monthly_income" min="0" class="input-field" placeholder="0.00" required>
                        </div>
                    </div>
                    <div>
                        <label class="input-label">Other Income / Side Hustles (₹)</label>
                        <div class="input-wrapper">
                            <i data-lucide="trending-up" class="input-icon"></i>
                            <input type="number" name="other_income" min="0" class="input-field" placeholder="0.00" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- STEP 3: Expenses (AI Expanded) -->
            <div class="step-container" id="step-3">
                
                <!-- AI Upload Section -->
                <div id="aiUploadSection" class="mb-10 border-2 border-dashed border-[#0A2540] border-opacity-20 bg-slate-50 rounded-3xl p-8 sm:p-10 text-center transition-all hover:bg-slate-100 hover:border-opacity-50 cursor-pointer group" onclick="document.getElementById('aiFileInput').click()">
                    <div class="w-16 h-16 bg-white rounded-full shadow-sm flex items-center justify-center mx-auto mb-4 group-hover:scale-110 transition-transform">
                        <i data-lucide="sparkles" class="w-8 h-8 text-[#c9a962]"></i>
                    </div>
                    <h3 class="font-bold text-slate-800 text-xl mb-2">Auto-fill with Bank Statement</h3>
                    <p class="text-sm text-slate-500 max-w-md mx-auto">Upload your PhonePe, GPay, or Bank PDF statement. We will automatically extract and average your spending from the <b>last 2 months</b>.</p>
                    <button type="button" class="mt-6 bg-[#0A2540] text-white px-6 py-2.5 rounded-full text-sm font-bold hover:bg-slate-800 transition">Select PDF File</button>
                    <input type="file" id="aiFileInput" accept="application/pdf" multiple class="hidden" onchange="processAIUpload(event)">
                </div>

                <!-- AI Processing State -->
                <div id="aiProcessingState" class="hidden mb-10 bg-[#0A2540] rounded-3xl p-8 text-center shadow-lg relative overflow-hidden">
                    <div class="absolute inset-0 opacity-10 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyMCIgaGVpZ2h0PSIyMCI+PHBhdGggZD0iTTAgMGgyMHYyMEgwem0xMCAxMGgxMHYxMEgxMHoiIGZpbGw9IiNmZmYiIGZpbGwtb3BhY2l0eT0iLjA1IiBmaWxsLXJ1bGU9ImV2ZW5vZGQiLz48L3N2Zz4=')]"></div>
                    <div class="relative z-10">
                        <div class="loader rounded-full border-4 border-t-4 border-slate-600 border-t-[#c9a962] h-12 w-12 mx-auto mb-4"></div>
                        <p class="font-bold text-white text-lg">Finora AI is reading your statements...</p>
                        <p class="text-sm text-slate-300 mt-2">Extracting and filtering data for the last 60 days. This takes ~15 seconds.</p>
                    </div>
                </div>

                <!-- AI Success State -->
                <div id="aiSuccessState" class="hidden mb-10 bg-green-50 border border-green-200 rounded-3xl p-6 sm:p-8">
                    <div class="flex flex-col sm:flex-row items-center gap-5">
                        <div class="w-14 h-14 bg-green-100 rounded-full flex items-center justify-center flex-shrink-0 shadow-inner">
                            <i data-lucide="check-circle" class="w-8 h-8 text-green-600"></i>
                        </div>
                        <div class="text-center sm:text-left flex-1">
                            <p class="font-bold text-green-900 text-lg">Data Extracted Successfully!</p>
                            <p class="text-sm text-green-700 mt-1" id="aiSuccessText">Found 0 transactions.</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-6 border-t border-green-200/50 pt-5" id="aiCategoryChips">
                        <!-- Chips inserted via JS -->
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    <div>
                        <label class="input-label">Rent / EMI</label>
                        <div class="input-wrapper"><i data-lucide="home" class="input-icon"></i>
                        <input type="number" name="rent_emi" id="exp_rent" min="0" class="input-field ai-target" placeholder="0" required></div>
                    </div>
                    <div>
                        <label class="input-label">Utilities & Bills</label>
                        <div class="input-wrapper"><i data-lucide="zap" class="input-icon"></i>
                        <input type="number" name="utilities" id="exp_utilities" min="0" class="input-field ai-target" placeholder="0" required></div>
                    </div>
                    <div>
                        <label class="input-label">Food & Groceries</label>
                        <div class="input-wrapper"><i data-lucide="shopping-cart" class="input-icon"></i>
                        <input type="number" name="food" id="exp_food" min="0" class="input-field ai-target" placeholder="0" required></div>
                    </div>
                    <div>
                        <label class="input-label">Transport & Fuel</label>
                        <div class="input-wrapper"><i data-lucide="car" class="input-icon"></i>
                        <input type="number" name="transport" id="exp_transport" min="0" class="input-field ai-target" placeholder="0" required></div>
                    </div>
                    <div>
                        <label class="input-label">Shopping & Lifestyle</label>
                        <div class="input-wrapper"><i data-lucide="shopping-bag" class="input-icon"></i>
                        <input type="number" name="lifestyle" id="exp_lifestyle" min="0" class="input-field ai-target" placeholder="0" required></div>
                    </div>
                    <div>
                        <label class="input-label">Healthcare</label>
                        <div class="input-wrapper"><i data-lucide="heart-pulse" class="input-icon"></i>
                        <input type="number" name="healthcare" id="exp_healthcare" min="0" class="input-field ai-target" placeholder="0" required></div>
                    </div>
                    <div>
                        <label class="input-label">Education</label>
                        <div class="input-wrapper"><i data-lucide="book-open" class="input-icon"></i>
                        <input type="number" name="education" id="exp_education" min="0" class="input-field ai-target" placeholder="0" required></div>
                    </div>
                    <div>
                        <label class="input-label">Entertainment</label>
                        <div class="input-wrapper"><i data-lucide="film" class="input-icon"></i>
                        <input type="number" name="entertainment" id="exp_entertainment" min="0" class="input-field ai-target" placeholder="0" required></div>
                    </div>
                    <div>
                        <label class="input-label">Other Expenses</label>
                        <div class="input-wrapper"><i data-lucide="more-horizontal" class="input-icon"></i>
                        <input type="number" name="other_expenses" id="exp_other" min="0" class="input-field ai-target" placeholder="0" required></div>
                    </div>
                </div>
            </div>

            <!-- STEP 4: Financial State -->
            <div class="step-container" id="step-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 sm:gap-8">
                    <div>
                        <label class="input-label">Total Liquid Savings (₹)</label>
                        <div class="input-wrapper">
                            <i data-lucide="piggy-bank" class="input-icon"></i>
                            <input type="number" name="savings" min="0" class="input-field" placeholder="0.00" required>
                        </div>
                    </div>
                    <div>
                        <label class="input-label">Total Investments (₹)</label>
                        <div class="input-wrapper">
                            <i data-lucide="line-chart" class="input-icon"></i>
                            <input type="number" name="investments" min="0" class="input-field" placeholder="0.00" required>
                        </div>
                    </div>
                    <div>
                        <label class="input-label">Total Outstanding Debt (₹)</label>
                        <div class="input-wrapper">
                            <i data-lucide="credit-card" class="input-icon"></i>
                            <input type="number" name="debt" min="0" class="input-field" placeholder="0.00" required>
                        </div>
                    </div>
                    <div>
                        <label class="input-label">Insurance Coverage (₹)</label>
                        <div class="input-wrapper">
                            <i data-lucide="shield-check" class="input-icon"></i>
                            <input type="number" name="insurance" min="0" class="input-field" placeholder="0.00" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- STEP 5: Goals -->
            <div class="step-container" id="step-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 sm:gap-8">
                    <div class="sm:col-span-2">
                        <label class="input-label">Primary Financial Goal</label>
                        <div class="input-wrapper">
                            <i data-lucide="target" class="input-icon"></i>
                            <input type="text" name="goal_name" class="input-field" placeholder="e.g. Buy a House, Retire Early" required>
                        </div>
                    </div>
                    <div>
                        <label class="input-label">Target Amount (₹)</label>
                        <div class="input-wrapper">
                            <i data-lucide="flag" class="input-icon"></i>
                            <input type="number" name="target_amount" min="1" class="input-field" placeholder="0.00" required>
                        </div>
                    </div>
                    <div>
                        <label class="input-label">Timeline (Years)</label>
                        <div class="input-wrapper">
                            <i data-lucide="hourglass" class="input-icon"></i>
                            <input type="number" name="goal_years" min="1" max="50" class="input-field" placeholder="e.g. 5" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- STEP 6: Risk & Behavior -->
            <div class="step-container" id="step-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 sm:gap-8 mb-8">
                    <div>
                        <label class="input-label">Risk Tolerance</label>
                        <div class="input-wrapper">
                            <i data-lucide="activity" class="input-icon"></i>
                            <select name="risk_tolerance" class="input-field" required>
                                <option value="" disabled selected>Select Option</option>
                                <option value="Low">Low (Safe, Stable Returns)</option>
                                <option value="Medium">Medium (Balanced)</option>
                                <option value="High">High (Aggressive Growth)</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="input-label">Biggest Financial Concern</label>
                        <div class="input-wrapper">
                            <i data-lucide="alert-circle" class="input-icon"></i>
                            <select name="primary_concern" class="input-field" required>
                                <option value="" disabled selected>Select Option</option>
                                <option value="Inflation">Inflation & Cost of Living</option>
                                <option value="Job Loss">Income Instability / Job Loss</option>
                                <option value="Debt">Paying off Debt</option>
                                <option value="Retirement">Not enough for Retirement</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between p-4 bg-slate-50 border border-slate-100 rounded-2xl hover:bg-slate-100 transition">
                        <div class="pr-4">
                            <p class="font-bold text-slate-800 text-sm">Track Expenses Manually</p>
                            <p class="text-xs text-slate-500 mt-0.5">Do you consistently track what you spend?</p>
                        </div>
                        <div class="relative inline-block w-14 mr-2 align-middle select-none">
                            <input type="checkbox" name="track_expenses" id="t1" class="toggle-checkbox absolute block w-7 h-7 rounded-full bg-white border-4 appearance-none cursor-pointer"/>
                            <label for="t1" class="toggle-label block overflow-hidden h-7 rounded-full bg-slate-300 cursor-pointer"></label>
                        </div>
                    </div>
                    <div class="flex items-center justify-between p-4 bg-slate-50 border border-slate-100 rounded-2xl hover:bg-slate-100 transition">
                        <div class="pr-4">
                            <p class="font-bold text-slate-800 text-sm">Invest Regularly</p>
                            <p class="text-xs text-slate-500 mt-0.5">Do you invest a portion of your income monthly?</p>
                        </div>
                        <div class="relative inline-block w-14 mr-2 align-middle select-none">
                            <input type="checkbox" name="invest_regularly" id="t2" class="toggle-checkbox absolute block w-7 h-7 rounded-full bg-white border-4 appearance-none cursor-pointer"/>
                            <label for="t2" class="toggle-label block overflow-hidden h-7 rounded-full bg-slate-300 cursor-pointer"></label>
                        </div>
                    </div>
                    <div class="flex items-center justify-between p-4 bg-slate-50 border border-slate-100 rounded-2xl hover:bg-slate-100 transition">
                        <div class="pr-4">
                            <p class="font-bold text-slate-800 text-sm">Emergency Fund Setup</p>
                            <p class="text-xs text-slate-500 mt-0.5">Do you have 3-6 months of expenses saved?</p>
                        </div>
                        <div class="relative inline-block w-14 mr-2 align-middle select-none">
                            <input type="checkbox" name="emergency_fund" id="t3" class="toggle-checkbox absolute block w-7 h-7 rounded-full bg-white border-4 appearance-none cursor-pointer"/>
                            <label for="t3" class="toggle-label block overflow-hidden h-7 rounded-full bg-slate-300 cursor-pointer"></label>
                        </div>
                    </div>
                    
                    <!-- Premium Feature Toggle -->
                    <div class="flex items-center justify-between p-5 bg-gradient-to-r from-[#0a2540] to-[#1e3a5f] rounded-2xl mt-6 shadow-lg border border-[#c9a962]/30">
                        <div class="pr-4">
                            <div class="flex items-center gap-2 mb-1">
                                <i data-lucide="cpu" class="w-4 h-4 text-[#c9a962]"></i>
                                <p class="font-bold text-white text-sm">Enable AI Autopilot</p>
                            </div>
                            <p class="text-xs text-slate-300">Let Finora X automatically suggest budget optimization and portfolio adjustments.</p>
                        </div>
                        <div class="relative inline-block w-14 mr-2 align-middle select-none">
                            <input type="checkbox" name="autopilot" id="t4" class="toggle-checkbox absolute block w-7 h-7 rounded-full bg-white border-4 appearance-none cursor-pointer" checked/>
                            <label for="t4" class="toggle-label block overflow-hidden h-7 rounded-full bg-slate-400 cursor-pointer"></label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer Navigation -->
            <div class="mt-10 pt-6 border-t border-slate-100 flex justify-between items-center bg-white z-20">
                <button type="button" id="prevBtn" class="px-5 py-3 sm:px-8 sm:py-3.5 rounded-xl font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition shadow-sm hidden text-sm sm:text-base flex items-center gap-2" onclick="changeStep(-1)">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i> Back
                </button>
                <div class="flex-1"></div>
                <button type="button" id="nextBtn" class="px-6 py-3 sm:px-10 sm:py-3.5 rounded-xl font-bold text-white bg-[#0A2540] hover:bg-[#153457] transition shadow-xl text-sm sm:text-base flex items-center gap-2" onclick="changeStep(1)">
                    Continue <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </button>
                <button type="submit" id="submitBtn" class="px-6 py-3 sm:px-10 sm:py-3.5 rounded-xl font-bold text-[#0a2540] bg-[#c9a962] hover:bg-[#d4b775] transition shadow-xl hidden text-sm sm:text-base flex items-center gap-2">
                    Complete Profile <i data-lucide="check-circle" class="w-4 h-4"></i>
                </button>
            </div>
            
            <div id="formError" class="bg-red-50 text-red-600 text-sm font-bold text-center mt-6 py-3 rounded-lg hidden border border-red-100 flex items-center justify-center gap-2">
                <i data-lucide="alert-triangle" class="w-4 h-4"></i> Please fill all required fields correctly to proceed.
            </div>

        </form>
    </div>

    <script>
        lucide.createIcons();

        const stepTitles = ["Basic Info", "Income Details", "Monthly Expenses", "Current Financial State", "Financial Goals", "Risk & Behavior"];
        let currentStep = 1;
        const totalSteps = 6;

        function updateUI() {
            // Hide all, show current with transition
            document.querySelectorAll('.step-container').forEach(el => el.classList.remove('active'));
            setTimeout(() => {
                document.getElementById(`step-${currentStep}`).classList.add('active');
            }, 50);

            // Update Desktop Steps
            for(let i=1; i<=totalSteps; i++) {
                let ind = document.getElementById(`ind-${i}`);
                if(ind) {
                    if(i < currentStep) {
                        ind.className = "step-indicator w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm bg-[#10b981] text-white ring-4 ring-green-50 shadow-md transition-all duration-500 relative z-10";
                        ind.innerHTML = '<i data-lucide="check" class="w-5 h-5"></i>';
                    } else if(i === currentStep) {
                        ind.className = "step-indicator w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm bg-[#0A2540] text-[#c9a962] ring-4 ring-slate-100 shadow-md transition-all duration-500 relative z-10 scale-110";
                        ind.innerHTML = i;
                    } else {
                        ind.className = "step-indicator w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm bg-slate-200 text-slate-500 ring-4 ring-slate-50 transition-all duration-500 relative z-10";
                        ind.innerHTML = i;
                    }
                }
            }
            lucide.createIcons();

            // Progress bar and Titles
            const pct = ((currentStep - 1) / (totalSteps - 1)) * 100;
            const progressFill = document.getElementById('progress-fill');
            if(progressFill) progressFill.style.width = pct + '%';
            
            const stepTitle = document.getElementById('step-title');
            if(stepTitle) stepTitle.innerText = stepTitles[currentStep-1];
            
            const mobileStep = document.getElementById('mobile-step');
            if(mobileStep) mobileStep.innerText = currentStep;

            // Buttons
            document.getElementById('prevBtn').classList.toggle('hidden', currentStep === 1);
            if(currentStep === totalSteps) {
                document.getElementById('nextBtn').classList.add('hidden');
                document.getElementById('submitBtn').classList.remove('hidden');
            } else {
                document.getElementById('nextBtn').classList.remove('hidden');
                document.getElementById('submitBtn').classList.add('hidden');
            }
            
            document.getElementById('formError').classList.add('hidden');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function validateCurrentStep() {
            const stepDiv = document.getElementById(`step-${currentStep}`);
            const inputs = stepDiv.querySelectorAll('input[required], select[required]');
            let isValid = true;
            inputs.forEach(input => {
                if(!input.value.trim()) {
                    input.classList.add('border-red-400', 'bg-red-50');
                    input.previousElementSibling.classList.add('text-red-500'); // icon color
                    isValid = false;
                } else {
                    input.classList.remove('border-red-400', 'bg-red-50');
                    if(input.previousElementSibling) input.previousElementSibling.classList.remove('text-red-500');
                }
            });
            return isValid;
        }

        function changeStep(n) {
            if (n === 1 && !validateCurrentStep()) {
                document.getElementById('formError').classList.remove('hidden');
                return;
            }
            currentStep += n;
            if (currentStep < 1) currentStep = 1;
            if (currentStep > totalSteps) currentStep = totalSteps;
            updateUI();
        }

        // Remove error states on input
        document.querySelectorAll('input, select').forEach(el => {
            el.addEventListener('input', function() {
                this.classList.remove('border-red-400', 'bg-red-50');
                if(this.previousElementSibling) this.previousElementSibling.classList.remove('text-red-500');
            });
        });

        // ==========================================
        // AI Upload Logic (Enhanced)
        // ==========================================
        async function processAIUpload(event) {
            const files = event.target.files;
            if(files.length === 0) return;

            document.getElementById('aiUploadSection').classList.add('hidden');
            document.getElementById('aiProcessingState').classList.remove('hidden');
            document.getElementById('aiSuccessState').classList.add('hidden');

            const formData = new FormData();
            formData.append('action', 'upload_statement');
            for(let i=0; i<files.length; i++){
                formData.append('statements[]', files[i]);
            }

            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const text = await response.text();
                
                let result;
                try { result = JSON.parse(text); } 
                catch (e) { throw new Error("Server error processing PDF. File may be too large."); }

                if (result.error) throw new Error(result.error);
                
                if (result.success && result.data) {
                    // Expanded Category Mapping
                    let expMap = { 
                        rent: 0, utilities: 0, food: 0, transport: 0, 
                        lifestyle: 0, healthcare: 0, education: 0, entertainment: 0, other: 0 
                    };
                    
                    result.data.forEach(tx => {
                        if(tx.type === 'DEBIT') {
                            let amt = parseFloat(tx.amount) || 0;
                            let cat = tx.category;
                            
                            if (cat === 'Rent / EMI') expMap.rent += amt;
                            else if (cat === 'Utilities & Bills') expMap.utilities += amt;
                            else if (cat === 'Food & Groceries') expMap.food += amt;
                            else if (cat === 'Transport') expMap.transport += amt;
                            else if (cat === 'Shopping & Lifestyle') expMap.lifestyle += amt;
                            else if (cat === 'Healthcare') expMap.healthcare += amt;
                            else if (cat === 'Education') expMap.education += amt;
                            else if (cat === 'Entertainment') expMap.entertainment += amt;
                            else expMap.other += amt;
                        }
                    });

                    // Build Success UI
                    document.getElementById('aiProcessingState').classList.add('hidden');
                    document.getElementById('aiSuccessState').classList.remove('hidden');
                    
                    const meta = result.meta;
                    document.getElementById('aiSuccessText').innerHTML = `Found <b>${meta.total_found}</b> total transactions.<br>Filtered to <b>${meta.filtered_count}</b> transactions from the latest 2 months.`;

                    // Generate mini chips for found categories
                    const chipsContainer = document.getElementById('aiCategoryChips');
                    chipsContainer.innerHTML = '';
                    for (const [key, val] of Object.entries(expMap)) {
                        if (val > 0) {
                            let cleanKey = key.charAt(0).toUpperCase() + key.slice(1);
                            chipsContainer.innerHTML += `
                                <div class="bg-white px-3 py-2 rounded-lg border border-green-100 text-center shadow-sm">
                                    <p class="text-[10px] text-slate-500 uppercase font-bold truncate">${cleanKey}</p>
                                    <p class="text-sm font-bold text-green-700">₹${Math.round(val)}</p>
                                </div>
                            `;
                        }
                    }

                    // Auto-fill and Animate inputs
                    animateValue("exp_rent", expMap.rent);
                    animateValue("exp_utilities", expMap.utilities);
                    animateValue("exp_food", expMap.food);
                    animateValue("exp_transport", expMap.transport);
                    animateValue("exp_lifestyle", expMap.lifestyle);
                    animateValue("exp_healthcare", expMap.healthcare);
                    animateValue("exp_education", expMap.education);
                    animateValue("exp_entertainment", expMap.entertainment);
                    animateValue("exp_other", expMap.other);
                }
            } catch (error) {
                alert(error.message);
                document.getElementById('aiProcessingState').classList.add('hidden');
                document.getElementById('aiUploadSection').classList.remove('hidden');
            }
            event.target.value = ''; 
        }

        function animateValue(id, end) {
            const obj = document.getElementById(id);
            if(!obj) return;
            
            // Trigger visual highlight class
            obj.classList.add('ai-filled');
            setTimeout(() => obj.classList.remove('ai-filled'), 2000);

            if(end === 0) {
                obj.value = '';
                return;
            }
            
            let current = 0;
            const increment = end / 40; 
            const timer = setInterval(() => {
                current += increment;
                if (current >= end) {
                    obj.value = Math.round(end);
                    clearInterval(timer);
                } else {
                    obj.value = Math.round(current);
                }
            }, 15);
        }

        // ==========================================
        // Final Submission
        // ==========================================
        document.getElementById('onboardingForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            if(!validateCurrentStep()) {
                document.getElementById('formError').classList.remove('hidden');
                return;
            }

            const submitBtn = document.getElementById('submitBtn');
            const originalHTML = submitBtn.innerHTML;
            submitBtn.innerHTML = '<div class="loader ease-linear rounded-full border-2 border-t-2 border-slate-200 h-5 w-5 mr-2"></div> Saving Profile...';
            submitBtn.disabled = true;

            const formData = new FormData(e.target);
            try {
                const res = await fetch('', { method: 'POST', body: formData });
                const result = await res.json();
                if(result.success) {
                    submitBtn.innerHTML = 'Redirecting... <i data-lucide="check" class="w-4 h-4 ml-1"></i>';
                    lucide.createIcons();
                    setTimeout(() => window.location.href = 'dashboard.php', 600);
                } else {
                    alert(result.error || "Submission failed.");
                    submitBtn.innerHTML = originalHTML;
                    submitBtn.disabled = false;
                }
            } catch (err) {
                alert("Network error occurred. Please try again.");
                submitBtn.innerHTML = originalHTML;
                submitBtn.disabled = false;
            }
        });

        // Initialize UI
        updateUI();
    </script>
</body>
</html>