<?php
require 'auth.php'; // Ensures $_SESSION['user_id'] is set
require 'config.php'; // Database connection $pdo and $GEMINI_API_KEY array

$user_id = $_SESSION['user_id'] ?? null;

// Ensure Gemini API key is an array format for fallback mechanism
if (isset($GEMINI_API_KEY) && is_string($GEMINI_API_KEY)) {
    $GEMINI_API_KEY = [$GEMINI_API_KEY];
}

// =========================================================================
// 1. FETCH GOAL DATA & PROFILE
// =========================================================================
$stmt = $pdo->prepare("SELECT * FROM financial_profiles WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$user_id]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

// If no profile, we can't plan.
$hasProfile = (bool)$profile;

if ($hasProfile) {
    $goal_name = $profile['goal_name'] ?: 'Financial Freedom (FIRE)';
    $target_amount = (float)$profile['target_amount'];
    $goal_years = (int)$profile['goal_years'];
    if ($goal_years <= 0) $goal_years = 1; // Prevent division by zero
    $months = $goal_years * 12;
    $risk_tolerance = $profile['risk_tolerance'] ?: 'Medium';

    // Current Financials
    $monthly_income = (float)$profile['monthly_income'] + (float)$profile['other_income'];
    $monthly_expenses = (float)$profile['total_expenses'];
    $current_monthly_savings = max(0, $monthly_income - $monthly_expenses);
    $current_corpus = (float)$profile['savings'] + (float)$profile['investments'];

    // =========================================================================
    // 2. ASSUMPTIONS & SIP CALCULATION
    // =========================================================================
    // Base expected return on Risk Tolerance
    if ($risk_tolerance === 'Low') {
        $annual_rate = 0.07; // 7%
        $alloc_equity = 20; $alloc_debt = 70; $alloc_gold = 10;
    } elseif ($risk_tolerance === 'High') {
        $annual_rate = 0.12; // 12%
        $alloc_equity = 80; $alloc_debt = 15; $alloc_gold = 5;
    } else {
        $annual_rate = 0.10; // 10%
        $alloc_equity = 60; $alloc_debt = 30; $alloc_gold = 10;
    }
    
    $monthly_rate = $annual_rate / 12;

    // A. Future Value of existing corpus compounded over goal years
    $corpus_fv = $current_corpus * pow((1 + $annual_rate), $goal_years);

    // B. Remaining Target to achieve via SIP
    $remaining_target = max(0, $target_amount - $corpus_fv);

    // C. Required SIP Formula: P = (FV * r) / ((1 + r)^n - 1)
    if ($remaining_target > 0 && $monthly_rate > 0) {
        $required_sip = ($remaining_target * $monthly_rate) / (pow((1 + $monthly_rate), $months) - 1);
    } else {
        $required_sip = 0;
    }

    // =========================================================================
    // 3. CURRENT TRAJECTORY & GAP ANALYSIS
    // =========================================================================
    // Project FV of current actual savings rate
    $projected_savings_fv = 0;
    if ($current_monthly_savings > 0 && $monthly_rate > 0) {
        $projected_savings_fv = $current_monthly_savings * (pow((1 + $monthly_rate), $months) - 1) / $monthly_rate;
    }

    $total_projected_fv = $corpus_fv + $projected_savings_fv;
    $gap = $target_amount - $total_projected_fv;
    
    $is_on_track = $gap <= 0;
    $trajectory_pct = $target_amount > 0 ? min(100, ($total_projected_fv / $target_amount) * 100) : 0;
}

// =========================================================================
// 4. OPTIONAL AI EXPLANATION (AJAX HANDLER)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_ai_plan') {
    ob_clean();
    header('Content-Type: application/json');

    if (!$hasProfile) {
        echo json_encode(['error' => 'No financial profile found.']);
        exit;
    }

    $prompt = "
    You are an expert financial planner. Review this user's data and provide a strictly JSON formatted action plan.
    Do NOT include markdown like ```json.
    
    User Data:
    - Goal: {$goal_name}
    - Target: ₹" . number_format($target_amount) . " in {$goal_years} years.
    - Risk Profile: {$risk_tolerance}
    - Monthly Income: ₹" . number_format($monthly_income) . "
    - Monthly Expenses: ₹" . number_format($monthly_expenses) . "
    - Current Monthly Savings: ₹" . number_format($current_monthly_savings) . "
    - Required Monthly SIP to hit goal: ₹" . number_format($required_sip) . "
    - On Track: " . ($is_on_track ? "Yes" : "No, Gap of ₹" . number_format($gap)) . "

    Output Format MUST BE exactly:
    {
      \"feasibility\": \"High/Medium/Low\",
      \"summary_advice\": \"A 2-sentence encouraging summary.\",
      \"action_steps\": [
        \"Actionable step 1 (e.g., Increase SIP by X amount)\",
        \"Actionable step 2 (e.g., Reduce expenses in category Y)\",
        \"Actionable step 3\"
      ]
    }
    ";

    $postData = ["contents" => [["parts" => [["text" => $prompt]]]], "generationConfig" => ["temperature" => 0.2]];
    $jsonData = json_encode($postData);

    $aiText = null;
    $success = false;

    foreach ($GEMINI_API_KEY as $key) {
        $ch = curl_init('https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=' . trim($key));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); 

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response !== false) {
            $responseData = json_decode($response, true);
            if ($httpCode === 200 && isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                $aiText = $responseData['candidates'][0]['content']['parts'][0]['text'];
                $success = true; break;
            }
        }
    }

    if ($success && $aiText) {
        // Clean markdown if present
        $aiText = str_replace(['```json', '```'], '', $aiText);
        $decoded = json_decode(trim($aiText), true);
        if ($decoded) {
            echo json_encode(['success' => true, 'data' => $decoded]);
            exit;
        }
    }
    echo json_encode(['error' => 'AI Generation failed. Please try again.']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FIRE & Goal Planner | Finora X</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    
    <style>
        :root { --primary: #0a2540; --accent: #c9a962; }
        body { font-family: 'DM Sans', sans-serif; background-color: #f8fafc; }
        .heading-font { font-family: 'Playfair Display', serif; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        .loader { border-top-color: #c9a962; animation: spinner 1s linear infinite; }
        @keyframes spinner { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body class="antialiased text-slate-900 bg-slate-50 flex h-screen overflow-hidden">

    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        
        <!-- Header -->
        <header class="h-16 flex items-center justify-between px-4 sm:px-6 bg-white border-b border-slate-100 shadow-sm z-10 flex-shrink-0">
            <button id="menu-toggle" class="p-2 -ml-2 text-slate-500 hover:text-[#0a2540] hover:bg-slate-100 rounded-lg transition-colors">
                <i data-lucide="menu" class="w-6 h-6"></i>
            </button>
            <div class="font-bold text-lg heading-font text-[#0a2540] tracking-wide">Finora <span class="text-[#c9a962]">X</span></div>
        </header>

        <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 custom-scrollbar relative">
            
            <?php if (!$hasProfile): ?>
                <div class="max-w-3xl mx-auto text-center py-20">
                    <div class="w-20 h-20 bg-slate-200 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i data-lucide="target" class="w-10 h-10 text-slate-400"></i>
                    </div>
                    <h2 class="text-2xl font-bold heading-font text-[#0a2540] mb-2">No Profile Found</h2>
                    <p class="text-slate-500 mb-6">Please complete your onboarding profile to unlock the FIRE Planner.</p>
                    <a href="onboarding.php" class="inline-flex items-center px-6 py-3 bg-[#0a2540] text-white font-bold rounded-xl hover:bg-[#153457] transition shadow-lg">Setup Profile</a>
                </div>
            <?php else: ?>

            <div class="max-w-7xl mx-auto space-y-6 sm:space-y-8">
                
                <!-- Page Title -->
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4">
                    <div>
                        <h1 class="heading-font text-3xl sm:text-4xl font-bold text-[#0a2540] flex items-center gap-3">
                            <i data-lucide="compass" class="w-8 h-8 text-[#c9a962]"></i> FIRE & Goal Planner
                        </h1>
                        <p class="text-slate-500 font-medium mt-1">Convert your dreams into actionable math.</p>
                    </div>
                    <div class="bg-white px-4 py-2 border border-slate-200 rounded-xl shadow-sm text-sm font-bold text-slate-600 flex items-center gap-2">
                        <i data-lucide="clock" class="w-4 h-4 text-slate-400"></i> Timeline: <span class="text-[#0a2540]"><?= $goal_years ?> Years</span>
                    </div>
                </div>

                <!-- 1. Key Metrics Row -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <!-- Goal Card -->
                    <div class="bg-[#0a2540] rounded-2xl p-6 shadow-xl relative overflow-hidden border border-[#1e3a5f]">
                        <div class="absolute top-0 right-0 p-4 opacity-10"><i data-lucide="flag" class="w-20 h-20 text-white"></i></div>
                        <p class="text-[#c9a962] font-bold text-xs tracking-wider uppercase mb-1 relative z-10">Primary Goal</p>
                        <h3 class="text-white text-xl font-bold mb-4 relative z-10 truncate"><?= htmlspecialchars($goal_name) ?></h3>
                        <p class="text-slate-400 text-xs uppercase tracking-wider font-bold mb-1 relative z-10">Target Amount</p>
                        <p class="text-white text-3xl font-bold relative z-10">₹<?= number_format($target_amount) ?></p>
                    </div>

                    <!-- Required SIP Card -->
                    <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200 flex flex-col justify-between">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-slate-500 font-bold text-xs tracking-wider uppercase mb-1">Required SIP</p>
                                <h3 class="text-[#0a2540] text-2xl font-bold">₹<?= number_format($required_sip) ?> <span class="text-sm text-slate-400 font-medium">/mo</span></h3>
                            </div>
                            <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center"><i data-lucide="trending-up" class="w-5 h-5"></i></div>
                        </div>
                        <p class="text-sm text-slate-500 font-medium mt-4 border-t border-slate-100 pt-3">
                            Assuming <span class="font-bold text-slate-800"><?= $annual_rate * 100 ?>%</span> return based on <span class="font-bold text-slate-800"><?= $risk_tolerance ?></span> risk profile.
                        </p>
                    </div>

                    <!-- Current Capability -->
                    <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200 flex flex-col justify-between">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-slate-500 font-bold text-xs tracking-wider uppercase mb-1">Current Savings</p>
                                <h3 class="text-[#0a2540] text-2xl font-bold">₹<?= number_format($current_monthly_savings) ?> <span class="text-sm text-slate-400 font-medium">/mo</span></h3>
                            </div>
                            <div class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-full flex items-center justify-center"><i data-lucide="wallet" class="w-5 h-5"></i></div>
                        </div>
                        <?php if ($current_monthly_savings >= $required_sip): ?>
                            <p class="text-sm text-emerald-600 font-bold mt-4 border-t border-emerald-50 pt-3 flex items-center gap-1"><i data-lucide="check-circle" class="w-4 h-4"></i> You are easily covering the required SIP.</p>
                        <?php else: ?>
                            <p class="text-sm text-red-500 font-bold mt-4 border-t border-red-50 pt-3 flex items-center gap-1"><i data-lucide="alert-triangle" class="w-4 h-4"></i> Shortfall of ₹<?= number_format($required_sip - $current_monthly_savings) ?>/mo.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 2. Trajectory & Gap Analysis -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    
                    <!-- Progress Chart Block -->
                    <div class="bg-white p-6 sm:p-8 rounded-2xl shadow-sm border border-slate-200">
                        <h2 class="text-lg font-bold text-[#0a2540] mb-6 flex items-center gap-2"><i data-lucide="bar-chart-2" class="w-5 h-5 text-[#c9a962]"></i> Trajectory Analysis</h2>
                        
                        <div class="space-y-6">
                            <!-- Progress Bar -->
                            <div>
                                <div class="flex justify-between text-sm font-bold mb-2">
                                    <span class="text-slate-600">Projected Value: <span class="text-[#0a2540]">₹<?= number_format($total_projected_fv) ?></span></span>
                                    <span class="text-slate-400">Target: ₹<?= number_format($target_amount) ?></span>
                                </div>
                                <div class="w-full h-4 bg-slate-100 rounded-full overflow-hidden flex relative shadow-inner">
                                    <?php $bg_color = $is_on_track ? 'bg-emerald-500' : 'bg-[#c9a962]'; ?>
                                    <div class="<?= $bg_color ?> h-full rounded-full transition-all duration-1000" style="width: <?= $trajectory_pct ?>%"></div>
                                    <!-- Goal Marker Line -->
                                    <?php if($trajectory_pct > 100): ?>
                                    <div class="absolute top-0 bottom-0 left-[100%] border-r-2 border-[#0a2540] z-10 -ml-0.5"></div>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-slate-400 font-medium mt-2 text-right"><?= number_format($trajectory_pct, 1) ?>% Funded</p>
                            </div>

                            <div class="grid grid-cols-2 gap-4 border-t border-slate-100 pt-6">
                                <div>
                                    <p class="text-xs font-bold text-slate-500 uppercase">Existing Corpus FV</p>
                                    <p class="text-lg font-bold text-[#0a2540]">₹<?= number_format($corpus_fv) ?></p>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-500 uppercase">Future Savings FV</p>
                                    <p class="text-lg font-bold text-[#0a2540]">₹<?= number_format($projected_savings_fv) ?></p>
                                </div>
                            </div>

                            <?php if (!$is_on_track): ?>
                                <div class="bg-red-50 border border-red-100 p-4 rounded-xl flex items-start gap-3 mt-4">
                                    <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mt-0.5"></i>
                                    <div>
                                        <p class="text-sm font-bold text-red-800">Gap Detected: ₹<?= number_format($gap) ?></p>
                                        <p class="text-xs text-red-600 mt-1 font-medium">At your current savings rate, you will fall short. Consider increasing your monthly investments or extending the timeline.</p>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="bg-emerald-50 border border-emerald-100 p-4 rounded-xl flex items-start gap-3 mt-4">
                                    <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600 mt-0.5"></i>
                                    <div>
                                        <p class="text-sm font-bold text-emerald-800">On Track!</p>
                                        <p class="text-xs text-emerald-700 mt-1 font-medium">Your current trajectory surpasses your target goal by ₹<?= number_format(abs($gap)) ?>. Keep compounding!</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Asset Allocation -->
                    <div class="bg-white p-6 sm:p-8 rounded-2xl shadow-sm border border-slate-200">
                        <h2 class="text-lg font-bold text-[#0a2540] mb-6 flex items-center gap-2"><i data-lucide="pie-chart" class="w-5 h-5 text-[#c9a962]"></i> Suggested Allocation</h2>
                        <p class="text-sm text-slate-500 mb-6">Based on your <span class="font-bold text-slate-800"><?= $risk_tolerance ?></span> risk profile, here is the optimal asset distribution to achieve the expected <?= $annual_rate * 100 ?>% return.</p>

                        <!-- Multi-color progress bar -->
                        <div class="w-full h-6 rounded-full overflow-hidden flex shadow-inner mb-6">
                            <div class="bg-[#0a2540] h-full flex items-center justify-center text-[10px] text-white font-bold" style="width: <?= $alloc_equity ?>%" title="Equity">Equity</div>
                            <div class="bg-[#c9a962] h-full flex items-center justify-center text-[10px] text-[#0a2540] font-bold" style="width: <?= $alloc_debt ?>%" title="Debt">Debt</div>
                            <div class="bg-slate-300 h-full flex items-center justify-center text-[10px] text-slate-700 font-bold" style="width: <?= $alloc_gold ?>%" title="Gold/Alt">Gold</div>
                        </div>

                        <div class="space-y-4">
                            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-3 h-3 rounded-full bg-[#0a2540]"></div>
                                    <span class="text-sm font-bold text-slate-700">Equity (Index Funds, Stocks)</span>
                                </div>
                                <span class="text-sm font-bold text-[#0a2540]"><?= $alloc_equity ?>%</span>
                            </div>
                            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-3 h-3 rounded-full bg-[#c9a962]"></div>
                                    <span class="text-sm font-bold text-slate-700">Debt (Bonds, FDs, PPF)</span>
                                </div>
                                <span class="text-sm font-bold text-[#0a2540]"><?= $alloc_debt ?>%</span>
                            </div>
                            <div class="flex items-center justify-between pb-1">
                                <div class="flex items-center gap-3">
                                    <div class="w-3 h-3 rounded-full bg-slate-300"></div>
                                    <span class="text-sm font-bold text-slate-700">Gold / Alternatives</span>
                                </div>
                                <span class="text-sm font-bold text-[#0a2540]"><?= $alloc_gold ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. AI Action Plan Generation -->
                <div class="bg-gradient-to-r from-[#0a2540] to-[#16385d] rounded-2xl p-6 sm:p-8 shadow-xl relative border border-[#1e3a5f]">
                    <!-- Decorative element -->
                    <div class="absolute right-0 top-0 w-64 h-full bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI4IiBoZWlnaHQ9IjgiPgo8cmVjdCB3aWR0aD0iOCIgaGVpZ2h0PSI4IiBmaWxsPSIjZmZmIiBmaWxsLW9wYWNpdHk9IjAuMDUiPjwvcmVjdD4KPC9zdmc+')] opacity-20"></div>
                    
                    <div class="relative z-10">
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6 border-b border-white/10 pb-6">
                            <div>
                                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                                    <i data-lucide="cpu" class="w-5 h-5 text-[#c9a962]"></i> AI Financial Advisor
                                </h2>
                                <p class="text-slate-300 text-sm mt-1">Get a personalized, step-by-step action plan to reach your goal.</p>
                            </div>
                            <button id="aiBtn" onclick="generateAIPlan()" class="bg-[#c9a962] text-[#0a2540] px-6 py-2.5 rounded-xl font-bold hover:bg-[#b8954d] transition shadow-lg flex items-center gap-2 flex-shrink-0">
                                <i data-lucide="sparkles" class="w-4 h-4"></i> Generate Action Plan
                            </button>
                        </div>

                        <!-- AI Loader -->
                        <div id="aiLoader" class="hidden text-center py-10">
                            <div class="loader rounded-full border-4 border-t-4 border-slate-600 border-t-[#c9a962] h-10 w-10 mx-auto mb-4"></div>
                            <p class="text-white font-bold">Finora AI is analyzing your data...</p>
                            <p class="text-slate-400 text-xs mt-1">Generating custom strategies based on your income and trajectory.</p>
                        </div>

                        <!-- AI Results -->
                        <div id="aiResultContainer" class="hidden">
                            <div class="flex items-center gap-3 mb-4">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Feasibility Score:</span>
                                <span id="aiFeasibility" class="px-3 py-1 bg-white/10 border border-white/20 text-[#c9a962] font-bold text-xs rounded-full">High</span>
                            </div>
                            
                            <p id="aiSummary" class="text-white text-lg font-medium leading-relaxed mb-6 font-serif italic border-l-4 border-[#c9a962] pl-4"></p>
                            
                            <div class="bg-white/5 border border-white/10 rounded-xl p-6">
                                <h4 class="text-white font-bold text-sm uppercase tracking-wider mb-4 flex items-center gap-2">
                                    <i data-lucide="list-checks" class="w-4 h-4 text-[#c9a962]"></i> Recommended Action Steps
                                </h4>
                                <ul id="aiStepsList" class="space-y-4">
                                    <!-- Injected via JS -->
                                </ul>
                            </div>
                        </div>

                        <!-- AI Error -->
                        <div id="aiError" class="hidden bg-red-500/10 border border-red-500/30 text-red-200 p-4 rounded-xl text-sm font-bold flex items-center gap-2">
                            <i data-lucide="alert-triangle" class="w-5 h-5"></i> <span id="aiErrorMsg"></span>
                        </div>
                    </div>
                </div>

            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        lucide.createIcons();

        // Sidebar Hook
        document.addEventListener('DOMContentLoaded', () => {
            const menuToggle = document.getElementById('menu-toggle');
            if(menuToggle) {
                menuToggle.addEventListener('click', () => {
                    if (typeof window.toggleSidebar === 'function') window.toggleSidebar();
                });
            }
        });

        // AI Generation Logic
        async function generateAIPlan() {
            const btn = document.getElementById('aiBtn');
            const loader = document.getElementById('aiLoader');
            const resultContainer = document.getElementById('aiResultContainer');
            const errorContainer = document.getElementById('aiError');

            // Reset UI
            btn.disabled = true;
            btn.classList.add('opacity-50', 'cursor-not-allowed');
            resultContainer.classList.add('hidden');
            errorContainer.classList.add('hidden');
            loader.classList.remove('hidden');

            const formData = new FormData();
            formData.append('action', 'generate_ai_plan');

            try {
                const response = await fetch('planner.php', { method: 'POST', body: formData });
                const res = await response.json();

                if (res.error) throw new Error(res.error);

                if (res.success && res.data) {
                    const data = res.data;
                    
                    // Populate UI
                    const feasBadge = document.getElementById('aiFeasibility');
                    feasBadge.innerText = data.feasibility || 'Unknown';
                    
                    // Color code feasibility
                    let feasLower = (data.feasibility || '').toLowerCase();
                    if(feasLower.includes('high')) feasBadge.className = 'px-3 py-1 bg-emerald-500/20 border border-emerald-500/30 text-emerald-400 font-bold text-xs rounded-full';
                    else if(feasLower.includes('low')) feasBadge.className = 'px-3 py-1 bg-red-500/20 border border-red-500/30 text-red-400 font-bold text-xs rounded-full';
                    else feasBadge.className = 'px-3 py-1 bg-amber-500/20 border border-amber-500/30 text-amber-400 font-bold text-xs rounded-full';

                    document.getElementById('aiSummary').innerText = `"${data.summary_advice}"`;

                    const ul = document.getElementById('aiStepsList');
                    ul.innerHTML = '';
                    if(data.action_steps && Array.isArray(data.action_steps)) {
                        data.action_steps.forEach((step, index) => {
                            ul.innerHTML += `
                                <li class="flex items-start gap-3">
                                    <div class="w-6 h-6 rounded-full bg-[#c9a962] text-[#0a2540] flex items-center justify-center font-bold text-xs flex-shrink-0 mt-0.5">${index + 1}</div>
                                    <span class="text-slate-300 text-sm leading-relaxed">${step}</span>
                                </li>
                            `;
                        });
                    }

                    loader.classList.add('hidden');
                    resultContainer.classList.remove('hidden');
                    lucide.createIcons();
                }
            } catch (err) {
                loader.classList.add('hidden');
                errorContainer.classList.remove('hidden');
                document.getElementById('aiErrorMsg').innerText = err.message || "Failed to generate plan.";
            } finally {
                btn.disabled = false;
                btn.classList.remove('opacity-50', 'cursor-not-allowed');
                btn.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> Regenerate Plan';
                lucide.createIcons();
            }
        }
    </script>
</body>
</html>