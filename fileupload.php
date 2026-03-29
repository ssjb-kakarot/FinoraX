<?php
require 'config.php'; // has a array named $GEMINI_API_KEY that has multiple api keys , use one after another if one fails

// Fallback: If for some reason $GEMINI_API_KEY is defined as a string, convert it to an array
if (isset($GEMINI_API_KEY) && is_string($GEMINI_API_KEY)) {
    $GEMINI_API_KEY = [$GEMINI_API_KEY];
}

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hide errors from the browser output

// =========================================================================
// CONFIGURATION
// =========================================================================

ini_set('memory_limit', '512M');
set_time_limit(300); // Increased time limit to handle multiple chunks

// =========================================================================
// BACKEND LOGIC
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['statements'])) {
    
    function sendJsonResponse($data) {
        ob_clean(); // Wipe any warnings/text that accidentally printed
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    if (empty($GEMINI_API_KEY) || !is_array($GEMINI_API_KEY)) {
        sendJsonResponse(['error' => 'Gemini API Key array is missing or invalid. Please add it to the config.php file.']);
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

    if (empty(trim($rawTextCombo))) {
        sendJsonResponse(['error' => 'Could not extract any text from the uploaded PDF.']);
    }

    // BREAK DOWN LARGE TEXT INTO CHUNKS (~35,000 chars per chunk to avoid AI limits)
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
    if (!empty(trim($currentChunk))) {
        $textChunks[] = $currentChunk;
    }

    $allTransactions = [];
    $chunkErrors = [];

    // PROCESS EACH CHUNK
    foreach ($textChunks as $chunkIndex => $chunk) {
        $prompt = "
        You are a highly accurate financial data extraction AI. 
        Extract ALL successful transactions from this bank statement chunk and return ONLY a valid JSON array of objects.
        Do NOT include any markdown formatting like ```json. Start directly with [ and end with ].
        If there are no transactions in this text, return an empty array [].
        
        Rules:
        1. Clean up the 'description' (remove UPI IDs, UTR numbers, and filler words like 'Paid to').
        2. Categorize into one of: 'Food & Groceries', 'Shopping', 'Bills & Utilities', 'Entertainment', 'Income', 'Wallet Transfer', 'Education', 'General / Transfer'.
        3. Amount MUST be a raw number (e.g. 150.50).
        
        Format EXACTLY like this:
        [
          {
            \"date\": \"YYYY-MM-DD HH:MM\", 
            \"description\": \"Cleaned up name\",
            \"type\": \"CREDIT\" or \"DEBIT\",
            \"amount\": 150.50,
            \"category\": \"Food & Groceries\",
            \"platform\": \"PhonePe\" or \"Google Pay\"
          }
        ]

        Raw Statement Text Chunk:
        " . $chunk;

        $postData = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $prompt]
                    ]
                ]
            ],
            "generationConfig" => [
                "temperature" => 0.1
            ]
        ];
        $jsonData = json_encode($postData);

        $aiText = null;
        $success = false;

        // ITERATE OVER API KEYS IN CASE OF FAILURE/RATE LIMITS
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
                    $success = true;
                    break; // Key worked, exit key loop and process response
                }
            }
        }

        if (!$success || $aiText === null) {
            $chunkErrors[] = "Chunk " . ($chunkIndex + 1) . " failed across all available API keys.";
            continue; // Skip merging for this chunk, proceed to next chunk
        }

        // Extract JSON block automatically
        if (preg_match('/\[.*\]/s', $aiText, $matches)) {
            $jsonString = $matches[0];
        } else {
            $jsonString = $aiText; 
        }

        // Clean any accidental markdown leftovers
        $jsonString = str_replace(['```json', '```'], '', $jsonString);

        $transactions = json_decode(trim($jsonString), true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($transactions)) {
            // MERGE CHUNK TRANSACTIONS INTO MASTER ARRAY
            $allTransactions = array_merge($allTransactions, $transactions);
        } else {
            $chunkErrors[] = "Chunk " . ($chunkIndex + 1) . " AI failed to output valid JSON.";
        }
    }

    if (empty($allTransactions)) {
        $errMsg = 'AI failed to extract any valid transactions from the document.';
        if (!empty($chunkErrors)) $errMsg .= ' Issues encountered: ' . implode(" | ", $chunkErrors);
        sendJsonResponse(['error' => $errMsg]);
    }

    // Sort all merged transactions by date
    usort($allTransactions, function($a, $b) {
        return strtotime($b['date'] ?? 0) - strtotime($a['date'] ?? 0);
    });

    sendJsonResponse(['success' => true, 'data' => $allTransactions]);
}
?>

<!-- =========================================================================
     FRONTEND (HTML/Tailwind/JS)
     ========================================================================= -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Bank Statement Analyzer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .loader { border-top-color: #8b5cf6; animation: spinner 1.5s linear infinite; }
        @keyframes spinner { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body class="bg-gray-50 font-sans text-gray-800">

    <div class="max-w-7xl mx-auto px-4 py-8">
        
        <header class="mb-8 text-center">
            <h1 class="text-3xl font-bold text-gray-900 flex items-center justify-center gap-2">
                <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                AI Statement Extractor
            </h1>
            <p class="text-gray-500 mt-2">Powered by Gemini 2.5 Flash. Upload your PhonePe or GPay PDF statements.</p>
        </header>

        <!-- Upload Section -->
        <div id="uploadSection" class="bg-white p-8 rounded-xl shadow-sm border border-gray-200 text-center max-w-2xl mx-auto transition-all">
            <form id="uploadForm" enctype="multipart/form-data">
                <div class="border-2 border-dashed border-gray-300 rounded-lg p-10 hover:bg-gray-50 transition cursor-pointer" onclick="document.getElementById('fileInput').click()">
                    <svg class="w-12 h-12 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                    <p class="text-gray-600 font-medium">Click to upload or drag and drop</p>
                    <p class="text-sm text-gray-500 mt-1">PDF Files only</p>
                    <input type="file" id="fileInput" name="statements[]" accept="application/pdf" multiple class="hidden" onchange="handleFileSelect(event)">
                </div>
                <div id="fileNames" class="mt-4 text-sm text-gray-600 font-medium hidden"></div>
                <button type="submit" id="submitBtn" class="mt-6 w-full bg-purple-600 text-white font-semibold py-3 rounded-lg hover:bg-purple-700 transition hidden">Analyze with AI</button>
            </form>

            <div id="loading" class="hidden mt-6">
                <div class="loader ease-linear rounded-full border-4 border-t-4 border-gray-200 h-10 w-10 mx-auto mb-3"></div>
                <p class="text-gray-600 font-medium" id="loadingText">AI is reading your statement. This takes ~5-15 seconds...</p>
            </div>
            
            <div id="errorBox" class="hidden mt-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded relative text-left">
                <strong class="font-bold">Error: </strong>
                <span class="block sm:inline" id="errorMsg"></span>
                <button onclick="location.reload()" class="mt-3 bg-red-100 px-3 py-1 rounded text-sm hover:bg-red-200 font-semibold text-red-800">Try Again</button>
            </div>
        </div>

        <!-- Dashboard Section -->
        <div id="dashboardSection" class="hidden">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">Transaction Dashboard</h2>
                <div class="flex gap-3">
                    <button onclick="location.reload()" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 font-medium">Upload More</button>
                    <button onclick="exportCSV()" class="bg-gray-900 text-white px-4 py-2 rounded-lg hover:bg-black font-medium flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                        Export CSV
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-sm text-gray-500 font-medium">Total Spent (Debits)</p>
                    <p class="text-3xl font-bold text-red-600 mt-2">₹<span id="totalDebit">0.00</span></p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-sm text-gray-500 font-medium">Total Received (Credits)</p>
                    <p class="text-3xl font-bold text-green-600 mt-2">₹<span id="totalCredit">0.00</span></p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-sm text-gray-500 font-medium">AI Extracted Rows</p>
                    <p class="text-3xl font-bold text-purple-600 mt-2"><span id="totalTxCount">0</span></p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="max-h-[600px] overflow-y-auto">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-gray-50 text-gray-600 sticky top-0">
                                <tr>
                                    <th class="py-3 px-4 font-semibold text-sm">Date</th>
                                    <th class="py-3 px-4 font-semibold text-sm">Description</th>
                                    <th class="py-3 px-4 font-semibold text-sm">Type</th>
                                    <th class="py-3 px-4 font-semibold text-sm">Amount</th>
                                    <th class="py-3 px-4 font-semibold text-sm">Category</th>
                                </tr>
                            </thead>
                            <tbody id="txBody" class="divide-y divide-gray-100 text-sm"></tbody>
                        </table>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <h3 class="font-bold text-gray-700 mb-4">Expenses by Category</h3>
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
        const categories = [
            'Food & Groceries', 'Shopping', 'Bills & Utilities', 
            'Entertainment', 'Income', 'Wallet Transfer', 
            'Education', 'General / Transfer'
        ];

        let txData = [];
        let chartInstance = null;

        function handleFileSelect(event) {
            const files = event.target.files;
            if(files.length > 0) {
                let names = Array.from(files).map(f => f.name).join(', ');
                document.getElementById('fileNames').textContent = `Selected: ${names}`;
                document.getElementById('fileNames').classList.remove('hidden');
                document.getElementById('submitBtn').classList.remove('hidden');
                document.getElementById('errorBox').classList.add('hidden');
            }
        }

        function showError(message) {
            document.getElementById('loading').classList.add('hidden');
            document.getElementById('errorBox').classList.remove('hidden');
            document.getElementById('errorMsg').innerText = message;
            document.getElementById('submitBtn').classList.remove('hidden');
        }

        document.getElementById('uploadForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const fileInput = document.getElementById('fileInput');
            if (fileInput.files.length === 0) return alert('Please select a PDF file first.');

            document.getElementById('submitBtn').classList.add('hidden');
            document.getElementById('errorBox').classList.add('hidden');
            document.getElementById('loading').classList.remove('hidden');
            
            // Helpful loading text updates
            setTimeout(() => { document.getElementById('loadingText').innerText = "Still processing... large PDFs take a bit longer."; }, 15000);

            const formData = new FormData(e.target);

            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const textResult = await response.text(); 
                
                let result;
                try {
                    result = JSON.parse(textResult);
                } catch (e) {
                    console.error("Raw Error Dump:", textResult);
                    return showError("Server returned an invalid response. The file might be too large or corrupted.");
                }

                if (result.error) {
                    return showError(result.error);
                }

                if(result.success && result.data && result.data.length > 0) {
                    txData = result.data;
                    renderTable();
                    updateAnalytics();
                    document.getElementById('uploadSection').classList.add('hidden');
                    document.getElementById('dashboardSection').classList.remove('hidden');
                } else {
                    return showError('AI could not find any valid transactions in this document.');
                }

            } catch (error) {
                console.error(error);
                return showError('A network error occurred. Please check your internet connection and try again.');
            }
        });

        function renderTable() {
            const tbody = document.getElementById('txBody');
            tbody.innerHTML = '';
            
            txData.forEach((tx, index) => {
                const tr = document.createElement('tr');
                tr.className = "hover:bg-gray-50 transition";
                const typeColor = tx.type === 'CREDIT' ? 'text-green-600 bg-green-50' : 'text-red-600 bg-red-50';
                
                let optionsHtml = categories.map(cat => 
                    `<option value="${cat}" ${cat === tx.category ? 'selected' : ''}>${cat}</option>`
                ).join('');
                
                if(!categories.includes(tx.category) && tx.category) {
                    optionsHtml += `<option value="${tx.category}" selected>${tx.category} (AI)</option>`;
                }

                tr.innerHTML = `
                    <td class="py-3 px-4 text-gray-600 whitespace-nowrap">${tx.date}</td>
                    <td class="py-3 px-4 font-medium text-gray-800">${tx.description}</td>
                    <td class="py-3 px-4"><span class="px-2 py-1 rounded text-xs font-bold ${typeColor}">${tx.type}</span></td>
                    <td class="py-3 px-4 font-semibold">₹${parseFloat(tx.amount).toFixed(2)}</td>
                    <td class="py-3 px-4">
                        <select onchange="updateRowCategory(${index}, this.value)" class="text-sm border-gray-300 rounded p-1.5 bg-gray-50 focus:ring-purple-500 outline-none w-full">
                            ${optionsHtml}
                        </select>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function updateRowCategory(index, newCategory) {
            txData[index].category = newCategory;
            updateAnalytics();
        }

        function updateAnalytics() {
            let totalDebit = 0; let totalCredit = 0; let categoryTotals = {};
            txData.forEach(tx => {
                let amt = parseFloat(tx.amount) || 0;
                if(tx.type === 'CREDIT') totalCredit += amt;
                else {
                    totalDebit += amt;
                    if(tx.category !== 'Income' && tx.category !== 'Wallet Transfer') {
                        categoryTotals[tx.category] = (categoryTotals[tx.category] || 0) + amt;
                    }
                }
            });

            document.getElementById('totalDebit').innerText = totalDebit.toLocaleString('en-IN', {minimumFractionDigits: 2});
            document.getElementById('totalCredit').innerText = totalCredit.toLocaleString('en-IN', {minimumFractionDigits: 2});
            document.getElementById('totalTxCount').innerText = txData.length;
            renderChart(categoryTotals);
        }

        function renderChart(data) {
            const ctx = document.getElementById('categoryChart').getContext('2d');
            if(chartInstance) chartInstance.destroy();
            chartInstance = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: Object.keys(data),
                    datasets: [{
                        data: Object.values(data),
                        backgroundColor: ['#8b5cf6', '#3b82f6', '#ec4899', '#f59e0b', '#10b981', '#ef4444', '#64748b', '#14b8a6'],
                        borderWidth: 0
                    }]
                },
                options: { responsive: true, plugins: { legend: { position: 'bottom' } }, cutout: '70%' }
            });
        }

        function exportCSV() {
            let csv = 'Date,Description,Type,Amount,Category,Platform\n';
            txData.forEach(tx => {
                let desc = `"${(tx.description || '').toString().replace(/"/g, '""')}"`;
                let cat = `"${(tx.category || '').toString().replace(/"/g, '""')}"`;
                csv += `${tx.date},${desc},${tx.type},${tx.amount},${cat},"${tx.platform}"\n`;
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
            link.download = `AI_Statement_Export_${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>