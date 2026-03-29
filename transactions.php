<?php
require 'auth.php'; // Ensures $_SESSION['user_id'] is set
require 'config.php'; // Contains database connection $pdo and $GEMINI_API_KEY array

$user_id = $_SESSION['user_id'] ?? null;

// Ensure Gemini API key is an array format
if (isset($GEMINI_API_KEY) && is_string($GEMINI_API_KEY)) {
    $GEMINI_API_KEY = [$GEMINI_API_KEY];
}

// =========================================================================
// BACKEND LOGIC: HANDLE FORM SUBMISSIONS & AJAX
// =========================================================================

// --- 1. AI PDF UPLOAD (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_pdf') {
    ob_start();
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('memory_limit', '512M');
    set_time_limit(300);

    function sendJsonResponse($data) {
        ob_clean(); 
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    if (empty($GEMINI_API_KEY) || !is_array($GEMINI_API_KEY)) {
        sendJsonResponse(['error' => 'Gemini API Key missing in config.']);
    }

    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        sendJsonResponse(['error' => 'Dependencies missing. Please run "composer require smalot/pdfparser"']);
    }

    require __DIR__ . '/vendor/autoload.php';

    if (!isset($_FILES['statements'])) {
        sendJsonResponse(['error' => 'No file uploaded.']);
    }

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

    // Chunking text to avoid AI limits
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
        3. Amount MUST be a raw positive number.
        
        Format EXACTLY:
        [{\"date\": \"YYYY-MM-DD HH:MM\", \"description\": \"Cleaned Name\", \"type\": \"CREDIT\" or \"DEBIT\", \"amount\": 150.50, \"category\": \"Food & Groceries\", \"platform\": \"Bank/App\"}]

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

    // Check duplicates and Insert
    $added = 0;
    $skipped = 0;
    
    $checkStmt = $pdo->prepare("SELECT id FROM transactions WHERE user_id = ? AND date = ? AND amount = ? AND type = ?");
    $insertStmt = $pdo->prepare("INSERT INTO transactions (user_id, date, description, type, amount, category, platform) VALUES (?, ?, ?, ?, ?, ?, ?)");

    $pdo->beginTransaction();
    try {
        foreach ($allTransactions as $tx) {
            $date = !empty($tx['date']) ? date('Y-m-d H:i:s', strtotime($tx['date'])) : null;
            $amt = (float)($tx['amount'] ?? 0);
            $type = in_array(strtoupper($tx['type'] ?? ''), ['CREDIT', 'DEBIT']) ? strtoupper($tx['type']) : 'DEBIT';
            
            if ($date && $amt > 0) {
                // Duplicate prevention
                $checkStmt->execute([$user_id, $date, $amt, $type]);
                if (!$checkStmt->fetch()) {
                    $insertStmt->execute([
                        $user_id, $date, 
                        substr($tx['description'] ?? 'Unknown', 0, 250), 
                        $type, $amt, 
                        $tx['category'] ?? 'Other Expenses', 
                        $tx['platform'] ?? ''
                    ]);
                    $added++;
                } else {
                    $skipped++;
                }
            }
        }
        $pdo->commit();
        sendJsonResponse(['success' => true, 'added' => $added, 'skipped' => $skipped]);
    } catch (Exception $e) {
        $pdo->rollBack();
        sendJsonResponse(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

// --- 2. STANDARD FORM SUBMISSIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
        $date = $_POST['date'] ? date('Y-m-d H:i:s', strtotime($_POST['date'])) : date('Y-m-d H:i:s');
        $desc = substr($_POST['description'] ?? '', 0, 250);
        $amount = (float)($_POST['amount'] ?? 0);
        $type = in_array($_POST['type'], ['CREDIT','DEBIT']) ? $_POST['type'] : 'DEBIT';
        $category = $_POST['category'] ?? 'Other Expenses';

        if ($_POST['action'] === 'add') {
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, date, description, type, amount, category) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $date, $desc, $type, $amount, $category]);
            header("Location: transactions.php?msg=added");
        } else {
            $tx_id = (int)$_POST['tx_id'];
            $stmt = $pdo->prepare("UPDATE transactions SET date=?, description=?, type=?, amount=?, category=? WHERE id=? AND user_id=?");
            $stmt->execute([$date, $desc, $type, $amount, $category, $tx_id, $user_id]);
            header("Location: transactions.php?msg=updated");
        }
        exit;
    }

    if ($_POST['action'] === 'delete') {
        $tx_id = (int)$_POST['tx_id'];
        $stmt = $pdo->prepare("DELETE FROM transactions WHERE id=? AND user_id=?");
        $stmt->execute([$tx_id, $user_id]);
        header("Location: transactions.php?msg=deleted");
        exit;
    }

    if ($_POST['action'] === 'upload_csv' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file']['tmp_name'];
        if ($file) {
            $handle = fopen($file, "r");
            $row = 0; $added = 0; $skipped = 0;
            
            $checkStmt = $pdo->prepare("SELECT id FROM transactions WHERE user_id = ? AND date = ? AND amount = ? AND type = ?");
            $insertStmt = $pdo->prepare("INSERT INTO transactions (user_id, date, description, type, amount, category) VALUES (?, ?, ?, ?, ?, ?)");

            $pdo->beginTransaction();
            try {
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $row++; if ($row === 1) continue; // Skip header
                    
                    $date = isset($data[0]) ? date('Y-m-d H:i:s', strtotime($data[0])) : date('Y-m-d H:i:s');
                    $desc = substr($data[1] ?? 'Unknown', 0, 250);
                    $type = in_array(strtoupper(trim($data[2] ?? 'DEBIT')), ['CREDIT', 'DEBIT']) ? strtoupper(trim($data[2])) : 'DEBIT';
                    $amount = (float)($data[3] ?? 0);
                    $category = $data[4] ?? 'Other Expenses';
                    
                    $checkStmt->execute([$user_id, $date, $amount, $type]);
                    if (!$checkStmt->fetch()) {
                        $insertStmt->execute([$user_id, $date, $desc, $type, $amount, $category]);
                        $added++;
                    } else {
                        $skipped++;
                    }
                }
                $pdo->commit();
            } catch (Exception $e) { $pdo->rollBack(); }
            fclose($handle);
            header("Location: transactions.php?msg=csv_uploaded&added=$added&skipped=$skipped");
            exit;
        }
    }
}

// =========================================================================
// DATA FETCHING: FILTERS & PAGINATION
// =========================================================================

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$filter_type = $_GET['type'] ?? '';
$filter_category = $_GET['category'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$query = "SELECT * FROM transactions WHERE user_id = :user_id";
$params = [':user_id' => $user_id];

if ($filter_type !== '') { $query .= " AND type = :type"; $params[':type'] = $filter_type; }
if ($filter_category !== '') { $query .= " AND category = :category"; $params[':category'] = $filter_category; }
if ($date_from !== '') { $query .= " AND date >= :date_from"; $params[':date_from'] = $date_from . ' 00:00:00'; }
if ($date_to !== '') { $query .= " AND date <= :date_to"; $params[':date_to'] = $date_to . ' 23:59:59'; }

// Count total
$countQuery = str_replace("SELECT *", "SELECT COUNT(*) as total", $query);
$stmtCount = $pdo->prepare($countQuery);
$stmtCount->execute($params);
$totalRows = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRows / $limit);

// Fetch data
$query .= " ORDER BY date DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($query);
foreach ($params as $key => &$val) { $stmt->bindParam($key, $val); }
$stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories = ['Rent / EMI', 'Utilities & Bills', 'Food & Groceries', 'Transport', 'Shopping & Lifestyle', 'Healthcare', 'Education', 'Entertainment', 'Income', 'Other Expenses'];

// Messages mapping
$msgs = [
    'added' => 'Transaction added successfully.',
    'updated' => 'Transaction updated successfully.',
    'deleted' => 'Transaction deleted.',
    'csv_uploaded' => "CSV Processed! Added: " . ($_GET['added'] ?? 0) . " | Skipped (Duplicates): " . ($_GET['skipped'] ?? 0)
];
$alertMsg = isset($_GET['msg']) && isset($msgs[$_GET['msg']]) ? $msgs[$_GET['msg']] : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions | Finora X</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Lucide Icons -->
    <script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script>
    <!-- Custom Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    
    <style>
        :root { --primary: #0a2540; --accent: #c9a962; }
        body { font-family: 'DM Sans', sans-serif; background-color: #f8fafc; }
        .heading-font { font-family: 'Playfair Display', serif; }
        html { scroll-behavior: smooth; }
        
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-icon { position: absolute; left: 1rem; color: #94a3b8; width: 1.25rem; height: 1.25rem; transition: color 0.3s; }
        .input-field { background: #ffffff; border: 1.5px solid #e2e8f0; padding: 0.6rem 1rem 0.6rem 2.75rem; border-radius: 0.75rem; width: 100%; outline: none; transition: all 0.3s; font-weight: 500; color: #1e293b; font-size: 0.875rem; }
        .input-field:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(10,37,64,0.08); }
        .input-field:focus + .input-icon, .input-wrapper:focus-within .input-icon { color: var(--primary); }
        .input-label { display: block; font-size: 0.8rem; font-weight: 700; color: #475569; margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em; }

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

        <!-- Scrollable Body -->
        <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 custom-scrollbar relative">
            
            <?php if ($alertMsg): ?>
            <div id="alert-msg" class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-2 font-bold text-sm">
                    <i data-lucide="check-circle" class="w-5 h-5"></i> <?= $alertMsg ?>
                </div>
                <button onclick="document.getElementById('alert-msg').remove()"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <?php endif; ?>

            <div class="max-w-7xl mx-auto space-y-6">
                
                <!-- Page Title & Actions -->
                <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                    <div>
                        <h1 class="heading-font text-3xl font-bold text-[#0a2540]">Transactions</h1>
                        <p class="text-slate-500 font-medium mt-1 text-sm">Manage, categorize, and track your cash flow automatically.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button onclick="toggleModal('importModal')" class="inline-flex items-center justify-center px-4 py-2 bg-white text-[#0a2540] font-bold rounded-xl hover:bg-slate-50 transition-colors shadow-sm border border-slate-200 gap-2 text-sm">
                            <i data-lucide="sparkles" class="w-4 h-4 text-[#c9a962]"></i> Import AI / CSV
                        </button>
                        <button onclick="openAddModal()" class="inline-flex items-center justify-center px-4 py-2 bg-[#0a2540] text-white font-bold rounded-xl hover:bg-[#153457] transition-colors shadow-lg gap-2 text-sm border border-[#1e3a5f]">
                            <i data-lucide="plus" class="w-4 h-4 text-[#c9a962]"></i> Add Manual
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-100">
                    <form method="GET" action="transactions.php" class="flex flex-col sm:flex-row flex-wrap items-end gap-4">
                        <div class="w-full sm:w-auto flex-1 min-w-[150px]">
                            <label class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1 block">Type</label>
                            <select name="type" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-lg focus:ring-[#0a2540] focus:border-[#0a2540] block p-2 outline-none font-medium">
                                <option value="">All Types</option>
                                <option value="CREDIT" <?= $filter_type == 'CREDIT' ? 'selected' : '' ?>>Credit (Income)</option>
                                <option value="DEBIT" <?= $filter_type == 'DEBIT' ? 'selected' : '' ?>>Debit (Expense)</option>
                            </select>
                        </div>
                        <div class="w-full sm:w-auto flex-1 min-w-[180px]">
                            <label class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1 block">Category</label>
                            <select name="category" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-lg focus:ring-[#0a2540] focus:border-[#0a2540] block p-2 outline-none font-medium">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat ?>" <?= $filter_category == $cat ? 'selected' : '' ?>><?= $cat ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="w-full sm:w-auto flex-1 min-w-[140px]">
                            <label class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1 block">From Date</label>
                            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-lg p-2 outline-none font-medium">
                        </div>
                        <div class="w-full sm:w-auto flex-1 min-w-[140px]">
                            <label class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1 block">To Date</label>
                            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-lg p-2 outline-none font-medium">
                        </div>
                        <div class="w-full sm:w-auto flex gap-2">
                            <button type="submit" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2 px-4 rounded-lg text-sm transition">Filter</button>
                            <a href="transactions.php" class="bg-white hover:bg-red-50 text-slate-400 hover:text-red-500 border border-slate-200 font-bold py-2 px-3 rounded-lg text-sm transition flex items-center justify-center"><i data-lucide="x" class="w-4 h-4"></i></a>
                        </div>
                    </form>
                </div>

                <!-- Table Section -->
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-left text-sm text-slate-600 border-collapse whitespace-nowrap">
                            <thead class="bg-slate-50 text-xs uppercase font-bold text-slate-500 tracking-wider sticky top-0 z-10 border-b border-slate-100">
                                <tr>
                                    <th scope="col" class="px-6 py-4">Date</th>
                                    <th scope="col" class="px-6 py-4">Description</th>
                                    <th scope="col" class="px-6 py-4">Category</th>
                                    <th scope="col" class="px-6 py-4 text-right">Amount</th>
                                    <th scope="col" class="px-6 py-4 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-12 text-center text-slate-400 font-medium">
                                        <i data-lucide="folder-open" class="w-12 h-12 mx-auto mb-3 opacity-50"></i>
                                        No transactions found.
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $tx): 
                                        $isCredit = $tx['type'] === 'CREDIT';
                                        $amountColor = $isCredit ? 'text-emerald-600' : 'text-[#0a2540]';
                                        $sign = $isCredit ? '+' : '-';
                                        $bgRow = $isCredit ? 'hover:bg-emerald-50/30' : 'hover:bg-slate-50';
                                        $rawDate = date('Y-m-d\TH:i', strtotime($tx['date']));
                                    ?>
                                    <tr class="transition-colors <?= $bgRow ?>">
                                        <td class="px-6 py-4 font-medium text-slate-700">
                                            <?= date('M d, Y • h:i A', strtotime($tx['date'])) ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <p class="font-bold text-slate-800 truncate max-w-[200px] sm:max-w-xs" title="<?= htmlspecialchars($tx['description']) ?>">
                                                <?= htmlspecialchars($tx['description']) ?>
                                            </p>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200">
                                                <?= htmlspecialchars($tx['category'] ?? 'Other Expenses') ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-right font-bold <?= $amountColor ?> text-base">
                                            <?= $sign ?>₹<?= number_format($tx['amount'], 2) ?>
                                        </td>
                                        <td class="px-6 py-4 text-center">
                                            <div class="flex items-center justify-center gap-2">
                                                <button onclick="openEditModal(<?= $tx['id'] ?>, '<?= $rawDate ?>', '<?= htmlspecialchars(addslashes($tx['description'])) ?>', <?= $tx['amount'] ?>, '<?= $tx['type'] ?>', '<?= $tx['category'] ?>')" class="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition" title="Edit">
                                                    <i data-lucide="edit-2" class="w-4 h-4"></i>
                                                </button>
                                                <form method="POST" class="inline" onsubmit="return confirm('Delete this transaction?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="tx_id" value="<?= $tx['id'] ?>">
                                                    <button type="submit" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition" title="Delete">
                                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex items-center justify-between">
                        <p class="text-sm text-slate-500 font-medium">Page <span class="font-bold text-slate-800"><?= $page ?></span> of <span class="font-bold text-slate-800"><?= $totalPages ?></span></p>
                        <div class="flex gap-2">
                            <?php 
                            $queryStr = $_GET; 
                            if($page > 1) {
                                $queryStr['page'] = $page - 1;
                                $prevLink = '?' . http_build_query($queryStr);
                                echo "<a href='$prevLink' class='px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-sm font-bold text-slate-600 hover:bg-slate-100 transition shadow-sm'>Prev</a>";
                            }
                            if($page < $totalPages) {
                                $queryStr['page'] = $page + 1;
                                $nextLink = '?' . http_build_query($queryStr);
                                echo "<a href='$nextLink' class='px-3 py-1.5 bg-[#0a2540] border border-[#0a2540] rounded-lg text-sm font-bold text-white hover:bg-[#153457] transition shadow-sm'>Next</a>";
                            }
                            ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- ========================================== -->
    <!-- MODALS SECTION -->
    <!-- ========================================== -->

    <!-- Add/Edit Modal -->
    <div id="txModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 backdrop-blur-sm hidden opacity-0 transition-opacity duration-300 px-4">
        <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden transform scale-95 transition-transform duration-300" id="txModalContent">
            
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h3 class="text-lg font-bold text-[#0a2540] heading-font" id="modalTitle">Add Transaction</h3>
                <button onclick="toggleModal('txModal')" class="text-slate-400 hover:text-slate-700 bg-white rounded-full p-1 shadow-sm border border-slate-100 transition"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            
            <form method="POST" action="transactions.php" class="p-6 space-y-4">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="tx_id" id="formTxId" value="">

                <div>
                    <label class="input-label">Date & Time</label>
                    <div class="input-wrapper">
                        <i data-lucide="calendar" class="input-icon"></i>
                        <input type="datetime-local" name="date" id="formDate" required class="input-field" value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                </div>

                <div>
                    <label class="input-label">Description</label>
                    <div class="input-wrapper">
                        <i data-lucide="align-left" class="input-icon"></i>
                        <input type="text" name="description" id="formDesc" required placeholder="e.g., Grocery at Walmart" class="input-field">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="input-label">Amount (₹)</label>
                        <div class="input-wrapper">
                            <i data-lucide="indian-rupee" class="input-icon"></i>
                            <input type="number" step="0.01" min="0" name="amount" id="formAmount" required placeholder="0.00" class="input-field">
                        </div>
                    </div>
                    <div>
                        <label class="input-label">Type</label>
                        <div class="input-wrapper">
                            <i data-lucide="arrow-left-right" class="input-icon"></i>
                            <select name="type" id="formType" required class="input-field pr-2">
                                <option value="DEBIT">Debit (Out)</option>
                                <option value="CREDIT">Credit (In)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="input-label flex justify-between items-center">
                        Category
                    </label>
                    <div class="input-wrapper">
                        <i data-lucide="tag" class="input-icon"></i>
                        <select name="category" id="formCategory" required class="input-field">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat ?>"><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="pt-4 mt-2 border-t border-slate-100 flex gap-3">
                    <button type="button" onclick="toggleModal('txModal')" class="flex-1 py-2.5 bg-slate-100 text-slate-600 font-bold rounded-xl hover:bg-slate-200 transition">Cancel</button>
                    <button type="submit" class="flex-1 py-2.5 bg-[#0a2540] text-white font-bold rounded-xl hover:bg-[#153457] transition shadow-md" id="modalBtn">Save Record</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Import Modal (AI PDF or CSV) -->
    <div id="importModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 backdrop-blur-sm hidden opacity-0 transition-opacity duration-300 px-4">
        <div class="bg-white w-full max-w-2xl rounded-3xl shadow-2xl overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[90vh]" id="importModalContent">
            
            <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-[#0a2540] text-white">
                <div>
                    <h3 class="text-xl font-bold heading-font flex items-center gap-2">
                        <i data-lucide="sparkles" class="w-5 h-5 text-[#c9a962]"></i> Import Transactions
                    </h3>
                    <p class="text-xs text-slate-300 mt-1">AI auto-cleans duplicates and categorizes everything.</p>
                </div>
                <button onclick="toggleModal('importModal')" class="text-slate-300 hover:text-white bg-white/10 rounded-full p-1.5 transition"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            
            <div class="p-6 overflow-y-auto grid grid-cols-1 md:grid-cols-2 gap-6 bg-slate-50">
                
                <!-- Left: AI PDF Processor -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 relative overflow-hidden">
                    <div class="absolute top-0 right-0 p-3 opacity-10"><i data-lucide="cpu" class="w-16 h-16 text-[#0a2540]"></i></div>
                    <h4 class="font-bold text-[#0a2540] text-lg mb-1 relative z-10">AI Bank Statement</h4>
                    <p class="text-xs text-slate-500 mb-5 relative z-10">Upload your PhonePe or Bank PDF. We extract and categorize.</p>
                    
                    <form id="aiUploadForm" enctype="multipart/form-data">
                        <div class="border-2 border-dashed border-[#0a2540]/30 bg-slate-50 rounded-xl p-6 text-center hover:bg-[#0a2540]/5 hover:border-[#0a2540]/60 transition cursor-pointer relative z-10 group" onclick="document.getElementById('pdfInput').click()">
                            <input type="file" id="pdfInput" name="statements[]" accept="application/pdf" multiple class="hidden" onchange="document.getElementById('pdfName').innerText = this.files.length + ' file(s) selected'; document.getElementById('aiSubmitBtn').classList.remove('hidden');">
                            <i data-lucide="file-text" class="w-8 h-8 mx-auto text-slate-400 group-hover:text-[#c9a962] transition mb-2"></i>
                            <p class="text-sm font-bold text-slate-700" id="pdfName">Select PDF File(s)</p>
                        </div>
                        <button type="submit" id="aiSubmitBtn" class="mt-4 w-full bg-[#c9a962] text-[#0a2540] font-bold py-2.5 rounded-xl hover:bg-[#b8954d] transition shadow-md hidden flex items-center justify-center gap-2">
                            <i data-lucide="zap" class="w-4 h-4"></i> Process via AI
                        </button>
                    </form>

                    <!-- Loader State -->
                    <div id="aiLoading" class="hidden mt-4 text-center">
                        <div class="loader rounded-full border-4 border-t-4 border-slate-200 h-8 w-8 mx-auto mb-2"></div>
                        <p class="text-sm font-bold text-[#0a2540]" id="aiLoadingText">Extracting details...</p>
                        <p class="text-xs text-slate-500">This takes ~10 seconds per page.</p>
                    </div>
                </div>

                <!-- Right: Standard CSV -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 relative overflow-hidden">
                    <div class="absolute top-0 right-0 p-3 opacity-10"><i data-lucide="table" class="w-16 h-16 text-emerald-600"></i></div>
                    <h4 class="font-bold text-[#0a2540] text-lg mb-1 relative z-10">Standard CSV</h4>
                    <p class="text-xs text-slate-500 mb-5 relative z-10">Upload a basic CSV (Date, Desc, Type, Amount, Category).</p>

                    <form method="POST" action="transactions.php" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload_csv">
                        <div class="border-2 border-dashed border-emerald-600/30 bg-emerald-50/30 rounded-xl p-6 text-center hover:bg-emerald-50 hover:border-emerald-600/60 transition cursor-pointer relative z-10 group" onclick="document.getElementById('csvInput').click()">
                            <input type="file" id="csvInput" name="csv_file" accept=".csv" required class="hidden" onchange="document.getElementById('csvName').innerText = this.files[0].name; document.getElementById('csvSubmitBtn').classList.remove('hidden');">
                            <i data-lucide="file-spreadsheet" class="w-8 h-8 mx-auto text-emerald-600/50 group-hover:text-emerald-600 transition mb-2"></i>
                            <p class="text-sm font-bold text-slate-700" id="csvName">Select CSV File</p>
                        </div>
                        <button type="submit" id="csvSubmitBtn" class="mt-4 w-full bg-[#0a2540] text-white font-bold py-2.5 rounded-xl hover:bg-[#153457] transition shadow-md hidden flex items-center justify-center gap-2">
                            <i data-lucide="upload-cloud" class="w-4 h-4"></i> Upload CSV
                        </button>
                    </form>
                </div>

            </div>
            
            <div id="aiErrorBox" class="hidden bg-red-50 text-red-600 px-6 py-4 border-t border-red-100 text-sm font-bold flex items-center gap-2">
                <i data-lucide="alert-triangle" class="w-5 h-5 flex-shrink-0"></i> <span id="aiErrorMsg"></span>
            </div>
            
        </div>
    </div>

    <!-- Scripts -->
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

        // Modal Management
        function toggleModal(id) {
            const modal = document.getElementById(id);
            const content = document.getElementById(id + 'Content');
            if (modal.classList.contains('hidden')) {
                modal.classList.remove('hidden');
                setTimeout(() => {
                    modal.classList.remove('opacity-0');
                    content.classList.remove('scale-95');
                }, 10);
            } else {
                modal.classList.add('opacity-0');
                content.classList.add('scale-95');
                setTimeout(() => modal.classList.add('hidden'), 300);
            }
        }

        // Setup Manual Add Modal
        function openAddModal() {
            document.getElementById('modalTitle').innerText = 'Add Transaction';
            document.getElementById('formAction').value = 'add';
            document.getElementById('formTxId').value = '';
            document.getElementById('formDesc').value = '';
            document.getElementById('formAmount').value = '';
            document.getElementById('modalBtn').innerText = 'Save Record';
            
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            document.getElementById('formDate').value = now.toISOString().slice(0,16);
            
            toggleModal('txModal');
        }

        // Setup Manual Edit Modal
        function openEditModal(id, date, desc, amount, type, category) {
            document.getElementById('modalTitle').innerText = 'Edit Transaction';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formTxId').value = id;
            document.getElementById('formDate').value = date;
            document.getElementById('formDesc').value = desc;
            document.getElementById('formAmount').value = amount;
            document.getElementById('formType').value = type;
            document.getElementById('formCategory').value = category;
            document.getElementById('modalBtn').innerText = 'Update Record';
            
            toggleModal('txModal');
        }

        // AI PDF AJAX Processing
        document.getElementById('aiUploadForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const fileInput = document.getElementById('pdfInput');
            if (fileInput.files.length === 0) return alert('Select PDF files first.');

            document.getElementById('aiSubmitBtn').classList.add('hidden');
            document.getElementById('aiErrorBox').classList.add('hidden');
            document.getElementById('aiLoading').classList.remove('hidden');
            
            setTimeout(() => { 
                const txt = document.getElementById('aiLoadingText');
                if(txt) txt.innerText = "Still reading... extracting data into database..."; 
            }, 10000);

            const formData = new FormData();
            formData.append('action', 'upload_pdf');
            for(let i=0; i<fileInput.files.length; i++) formData.append('statements[]', fileInput.files[i]);

            try {
                const response = await fetch('transactions.php', { method: 'POST', body: formData });
                const textResult = await response.text(); 
                
                let result;
                try { result = JSON.parse(textResult); } 
                catch (err) { throw new Error("Server returned an invalid response. File may be too large."); }

                if (result.error) throw new Error(result.error);

                if(result.success) {
                    alert(`AI Processing Complete!\n\nAdded: ${result.added}\nSkipped (Duplicates): ${result.skipped}`);
                    window.location.reload();
                }

            } catch (error) {
                document.getElementById('aiLoading').classList.add('hidden');
                document.getElementById('aiErrorBox').classList.remove('hidden');
                document.getElementById('aiErrorMsg').innerText = error.message;
                document.getElementById('aiSubmitBtn').classList.remove('hidden');
            }
        });
    </script>
</body>
</html>