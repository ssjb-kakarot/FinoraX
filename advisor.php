<?php
require 'auth.php'; // Ensures $_SESSION['user_id'] is set
require 'config.php'; // Database connection $pdo and $GEMINI_API_KEY array

$user_id = $_SESSION['user_id'] ?? null;

// Ensure Gemini API key is an array format for fallback mechanism
if (isset($GEMINI_API_KEY) && is_string($GEMINI_API_KEY)) {
    $GEMINI_API_KEY = [$GEMINI_API_KEY];
}

// =========================================================================
// 1. FETCH CONTEXT DATA (For AI Prompt Injection)
// =========================================================================
$context = [
    'income' => 0, 'expenses' => 0, 'savings_rate' => 0,
    'goal' => 'None', 'target' => 0, 'timeline' => 0,
    'top_categories' => 'None'
];

$profileStmt = $pdo->prepare("SELECT * FROM financial_profiles WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$profileStmt->execute([$user_id]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

if ($profile) {
    $context['income'] = (float)$profile['monthly_income'] + (float)$profile['other_income'];
    $context['expenses'] = (float)$profile['total_expenses'];
    $context['savings_rate'] = (float)$profile['savings_rate'];
    $context['goal'] = $profile['goal_name'] ?: 'Financial Freedom';
    $context['target'] = (float)$profile['target_amount'];
    $context['timeline'] = (int)$profile['goal_years'];
}

$txStmt = $pdo->prepare("SELECT category, SUM(amount) as total FROM transactions WHERE user_id = ? AND type = 'DEBIT' GROUP BY category ORDER BY total DESC LIMIT 3");
$txStmt->execute([$user_id]);
$topTx = $txStmt->fetchAll(PDO::FETCH_ASSOC);
if ($topTx) {
    $catArr = [];
    foreach ($topTx as $tx) {
        $catArr[] = $tx['category'] . ' (₹' . number_format($tx['total']) . ')';
    }
    $context['top_categories'] = implode(', ', $catArr);
}

// =========================================================================
// 2. AJAX CHAT HANDLER
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_chat') {
    ob_clean();
    header('Content-Type: application/json');

    $user_message = trim($_POST['message'] ?? '');
    if (empty($user_message)) {
        echo json_encode(['error' => 'Message cannot be empty.']);
        exit;
    }

    // A. Save User Message to ai_logs
    $logStmt = $pdo->prepare("INSERT INTO ai_logs (user_id, type, message, impact, priority, created_at) VALUES (?, 'chat_user', ?, 'None', 'Low', NOW())");
    $logStmt->execute([$user_id, $user_message]);

    // B. Build System Prompt with Context
    $prompt = "
    You are 'Finora AI', an expert financial advisor for Indian users. Be practical, actionable, and concise.

    USER CONTEXT:
    - Monthly Income: ₹" . number_format($context['income']) . "
    - Monthly Expenses: ₹" . number_format($context['expenses']) . "
    - Savings Rate: " . $context['savings_rate'] . "%
    - Top Expense Areas: " . $context['top_categories'] . "
    - Current Goal: " . $context['goal'] . " (Target: ₹" . number_format($context['target']) . " in " . $context['timeline'] . " years)

    USER MESSAGE: \"{$user_message}\"

    Task: Respond to the user's message.
    Format your response STRICTLY as a JSON object with NO markdown formatting (do not use ```json).
    Structure exactly like this:
    {
      \"insight\": \"1-2 short sentences analyzing their specific question/situation based on the context.\",
      \"action\": \"1 specific, practical next step they can take today.\",
      \"numbers\": \"Any relevant calculation, percentage, or ₹ amount tied to the advice.\"
    }
    ";

    $postData = ["contents" => [["parts" => [["text" => $prompt]]]], "generationConfig" => ["temperature" => 0.3]];
    $jsonData = json_encode($postData);

    $aiText = null;
    $success = false;

    // C. Call Gemini API
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
        $aiText = trim(str_replace(['```json', '```'], '', $aiText));
        $aiData = json_decode($aiText, true);

        // Fallback if AI didn't return proper JSON
        if (!$aiData) {
            $aiData = [
                'insight' => $aiText,
                'action' => 'Review your recent transactions.',
                'numbers' => 'N/A'
            ];
        }

        // D. Save AI Response to ai_logs
        $jsonStringToSave = json_encode($aiData);
        $logStmt = $pdo->prepare("INSERT INTO ai_logs (user_id, type, message, impact, priority, created_at) VALUES (?, 'chat_ai', ?, 'High', 'Medium', NOW())");
        $logStmt->execute([$user_id, $jsonStringToSave]);

        echo json_encode(['success' => true, 'data' => $aiData]);
        exit;
    }

    echo json_encode(['error' => 'Finora AI is currently unavailable. Please try again.']);
    exit;
}

// =========================================================================
// 3. FETCH CHAT HISTORY ON LOAD
// =========================================================================
$historyStmt = $pdo->prepare("SELECT type, message, created_at FROM ai_logs WHERE user_id = ? AND type IN ('chat_user', 'chat_ai') ORDER BY created_at ASC");
$historyStmt->execute([$user_id]);
$chatHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>AI Financial Advisor | Finora X</title>
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
        
        .custom-scrollbar::-webkit-scrollbar { width: 5px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        .chat-bubble-enter { animation: slideUp 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards; opacity: 0; transform: translateY(10px); }
        @keyframes slideUp { to { opacity: 1; transform: translateY(0); } }

        .typing-dot { animation: typing 1.4s infinite ease-in-out both; }
        .typing-dot:nth-child(1) { animation-delay: -0.32s; }
        .typing-dot:nth-child(2) { animation-delay: -0.16s; }
        @keyframes typing { 0%, 80%, 100% { transform: scale(0); } 40% { transform: scale(1); } }
    </style>
</head>
<body class="antialiased text-slate-900 bg-slate-50 flex h-screen overflow-hidden">

    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden relative bg-slate-50">
        
        <!-- Header -->
        <header class="h-16 flex items-center justify-between px-4 sm:px-6 bg-white border-b border-slate-100 shadow-sm z-20 flex-shrink-0">
            <div class="flex items-center gap-4">
                <button id="menu-toggle" class="p-2 -ml-2 text-slate-500 hover:text-[#0a2540] hover:bg-slate-100 rounded-lg transition-colors">
                    <i data-lucide="menu" class="w-6 h-6"></i>
                </button>
                <div>
                    <h1 class="font-bold text-lg heading-font text-[#0a2540] tracking-wide flex items-center gap-2">
                        Finora <span class="text-[#c9a962]">AI</span>
                    </h1>
                    <p class="text-[10px] sm:text-xs text-emerald-600 font-bold flex items-center gap-1 uppercase tracking-wider">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block animate-pulse"></span> Online
                    </p>
                </div>
            </div>
            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-[#0a2540] to-[#1e3a5f] border-2 border-[#c9a962] flex items-center justify-center shadow-md">
                <i data-lucide="cpu" class="w-4 h-4 text-[#c9a962]"></i>
            </div>
        </header>

        <!-- Chat Container -->
        <main id="chatBox" class="flex-1 overflow-y-auto p-4 sm:p-6 custom-scrollbar flex flex-col gap-6 relative z-10 pb-32 sm:pb-40">
            
            <!-- Welcome AI Message -->
            <div class="flex gap-3">
                <div class="w-8 h-8 rounded-full bg-[#0a2540] flex-shrink-0 flex items-center justify-center border border-[#c9a962] shadow-sm">
                    <i data-lucide="sparkles" class="w-4 h-4 text-[#c9a962]"></i>
                </div>
                <div class="bg-white p-4 rounded-2xl rounded-tl-none border border-slate-200 shadow-sm max-w-[85%] sm:max-w-[70%]">
                    <p class="text-sm text-slate-700 font-medium leading-relaxed">
                        Hi! I'm Finora AI, your personal wealth advisor. I analyze your profile and transactions to give you practical, actionable advice. How can I help you grow your net worth today?
                    </p>
                </div>
            </div>

            <!-- Render Chat History -->
            <?php foreach ($chatHistory as $chat): ?>
                <?php if ($chat['type'] === 'chat_user'): ?>
                    <!-- User Message -->
                    <div class="flex gap-3 justify-end chat-bubble-enter">
                        <div class="bg-[#0a2540] p-4 rounded-2xl rounded-tr-none shadow-md max-w-[85%] sm:max-w-[70%]">
                            <p class="text-sm text-white font-medium leading-relaxed"><?= htmlspecialchars($chat['message']) ?></p>
                        </div>
                    </div>
                <?php else: 
                    // AI Message Parsing
                    $aiData = json_decode($chat['message'], true);
                    if (!$aiData) {
                        $aiData = ['insight' => htmlspecialchars($chat['message']), 'action' => 'N/A', 'numbers' => 'N/A'];
                    }
                ?>
                    <!-- AI Message -->
                    <div class="flex gap-3 chat-bubble-enter">
                        <div class="w-8 h-8 rounded-full bg-[#0a2540] flex-shrink-0 flex items-center justify-center border border-[#c9a962] shadow-sm mt-1">
                            <i data-lucide="cpu" class="w-4 h-4 text-[#c9a962]"></i>
                        </div>
                        <div class="bg-white p-4 sm:p-5 rounded-2xl rounded-tl-none border border-slate-200 shadow-sm max-w-[90%] sm:max-w-[75%] space-y-3">
                            <!-- Insight -->
                            <div class="flex gap-2.5 items-start">
                                <i data-lucide="lightbulb" class="w-4 h-4 text-[#c9a962] flex-shrink-0 mt-0.5"></i>
                                <div>
                                    <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-0.5">Insight</p>
                                    <p class="text-sm text-slate-700 leading-relaxed font-medium"><?= htmlspecialchars($aiData['insight'] ?? '') ?></p>
                                </div>
                            </div>
                            <!-- Action -->
                            <div class="flex gap-2.5 items-start bg-emerald-50/50 p-3 rounded-xl border border-emerald-100/50">
                                <i data-lucide="check-circle" class="w-4 h-4 text-emerald-600 flex-shrink-0 mt-0.5"></i>
                                <div>
                                    <p class="text-xs font-bold text-emerald-600 uppercase tracking-wider mb-0.5">Action Plan</p>
                                    <p class="text-sm text-emerald-900 leading-relaxed font-bold"><?= htmlspecialchars($aiData['action'] ?? '') ?></p>
                                </div>
                            </div>
                            <!-- Numbers -->
                            <div class="flex gap-2.5 items-start bg-blue-50/50 p-3 rounded-xl border border-blue-100/50">
                                <i data-lucide="calculator" class="w-4 h-4 text-blue-600 flex-shrink-0 mt-0.5"></i>
                                <div>
                                    <p class="text-xs font-bold text-blue-600 uppercase tracking-wider mb-0.5">The Math</p>
                                    <p class="text-sm text-blue-900 leading-relaxed font-bold"><?= htmlspecialchars($aiData['numbers'] ?? '') ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <!-- Temporary Typing Indicator (Hidden by default) -->
            <div id="typingIndicator" class="hidden flex gap-3">
                <div class="w-8 h-8 rounded-full bg-[#0a2540] flex-shrink-0 flex items-center justify-center border border-[#c9a962] shadow-sm">
                    <i data-lucide="cpu" class="w-4 h-4 text-[#c9a962]"></i>
                </div>
                <div class="bg-white px-4 py-3.5 rounded-2xl rounded-tl-none border border-slate-200 shadow-sm flex items-center gap-1.5">
                    <div class="w-2 h-2 bg-[#c9a962] rounded-full typing-dot"></div>
                    <div class="w-2 h-2 bg-[#c9a962] rounded-full typing-dot"></div>
                    <div class="w-2 h-2 bg-[#c9a962] rounded-full typing-dot"></div>
                </div>
            </div>

            <!-- Scroll Anchor -->
            <div id="chatAnchor"></div>
        </main>

        <!-- Bottom Input Area (Fixed) -->
        <div class="absolute bottom-0 left-0 right-0 bg-white border-t border-slate-200 shadow-[0_-4px_20px_rgba(0,0,0,0.02)] z-20">
            
            <!-- Quick Actions Container -->
            <div class="px-4 pt-3 pb-2 flex gap-2 overflow-x-auto custom-scrollbar whitespace-nowrap bg-slate-50 border-b border-slate-100" id="quickActions">
                <button onclick="sendQuickAction('Analyze my recent spending categories and tell me where I can cut back.')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 text-slate-600 text-xs font-bold rounded-full hover:bg-[#0a2540] hover:text-white hover:border-[#0a2540] transition shadow-sm">
                    <i data-lucide="pie-chart" class="w-3.5 h-3.5"></i> Analyze Spending
                </button>
                <button onclick="sendQuickAction('How can I improve my savings rate right now?')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 text-slate-600 text-xs font-bold rounded-full hover:bg-[#0a2540] hover:text-white hover:border-[#0a2540] transition shadow-sm">
                    <i data-lucide="trending-up" class="w-3.5 h-3.5"></i> Improve Savings
                </button>
                <button onclick="sendQuickAction('Create an investment plan to hit my financial goal faster.')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 text-slate-600 text-xs font-bold rounded-full hover:bg-[#0a2540] hover:text-white hover:border-[#0a2540] transition shadow-sm">
                    <i data-lucide="target" class="w-3.5 h-3.5"></i> Plan Investment
                </button>
            </div>

            <!-- Input Form -->
            <form id="chatForm" class="p-3 sm:p-4 flex items-end gap-2 sm:gap-3 bg-white">
                <div class="flex-1 bg-slate-50 border border-slate-200 rounded-2xl px-4 py-2 sm:py-3 focus-within:border-[#0a2540] focus-within:ring-2 focus-within:ring-[#0a2540]/10 transition-all flex items-center">
                    <textarea id="chatInput" rows="1" placeholder="Ask Finora AI anything..." class="w-full bg-transparent text-sm font-medium text-slate-700 outline-none resize-none custom-scrollbar max-h-32" oninput="this.style.height = ''; this.style.height = Math.min(this.scrollHeight, 128) + 'px'"></textarea>
                </div>
                <button type="submit" id="sendBtn" class="w-10 h-10 sm:w-12 sm:h-12 flex-shrink-0 bg-[#0a2540] text-[#c9a962] rounded-full flex items-center justify-center hover:bg-[#153457] transition-all shadow-md focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed">
                    <i data-lucide="send" class="w-4 h-4 sm:w-5 sm:h-5 ml-1"></i>
                </button>
            </form>
        </div>

    </div>

    <script>
        lucide.createIcons();

        const chatBox = document.getElementById('chatBox');
        const chatInput = document.getElementById('chatInput');
        const chatForm = document.getElementById('chatForm');
        const sendBtn = document.getElementById('sendBtn');
        const typingIndicator = document.getElementById('typingIndicator');
        const chatAnchor = document.getElementById('chatAnchor');

        // Sidebar logic
        document.addEventListener('DOMContentLoaded', () => {
            const menuToggle = document.getElementById('menu-toggle');
            if(menuToggle) {
                menuToggle.addEventListener('click', () => {
                    if (typeof window.toggleSidebar === 'function') window.toggleSidebar();
                });
            }
            scrollToBottom();
        });

        function scrollToBottom() {
            chatAnchor.scrollIntoView({ behavior: 'smooth' });
        }

        // Handle Enter to Submit (Shift+Enter for new line)
        chatInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                chatForm.dispatchEvent(new Event('submit'));
            }
        });

        // Quick Action Wrapper
        function sendQuickAction(text) {
            chatInput.value = text;
            chatForm.dispatchEvent(new Event('submit'));
        }

        // Render User Bubble
        function appendUserMessage(text) {
            const div = document.createElement('div');
            div.className = 'flex gap-3 justify-end chat-bubble-enter';
            div.innerHTML = `
                <div class="bg-[#0a2540] p-4 rounded-2xl rounded-tr-none shadow-md max-w-[85%] sm:max-w-[70%]">
                    <p class="text-sm text-white font-medium leading-relaxed">${text.replace(/\n/g, '<br>')}</p>
                </div>
            `;
            chatBox.insertBefore(div, typingIndicator);
            scrollToBottom();
        }

        // Render AI Bubble
        function appendAIMessage(data) {
            const div = document.createElement('div');
            div.className = 'flex gap-3 chat-bubble-enter';
            
            // Format fallback safely
            const insight = data.insight ? data.insight.replace(/\n/g, '<br>') : 'Error generating insight.';
            const action = data.action ? data.action.replace(/\n/g, '<br>') : 'N/A';
            const numbers = data.numbers ? data.numbers.replace(/\n/g, '<br>') : 'N/A';

            div.innerHTML = `
                <div class="w-8 h-8 rounded-full bg-[#0a2540] flex-shrink-0 flex items-center justify-center border border-[#c9a962] shadow-sm mt-1">
                    <i data-lucide="cpu" class="w-4 h-4 text-[#c9a962]"></i>
                </div>
                <div class="bg-white p-4 sm:p-5 rounded-2xl rounded-tl-none border border-slate-200 shadow-sm max-w-[90%] sm:max-w-[75%] space-y-3">
                    <div class="flex gap-2.5 items-start">
                        <i data-lucide="lightbulb" class="w-4 h-4 text-[#c9a962] flex-shrink-0 mt-0.5"></i>
                        <div>
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-0.5">Insight</p>
                            <p class="text-sm text-slate-700 leading-relaxed font-medium">${insight}</p>
                        </div>
                    </div>
                    <div class="flex gap-2.5 items-start bg-emerald-50/50 p-3 rounded-xl border border-emerald-100/50">
                        <i data-lucide="check-circle" class="w-4 h-4 text-emerald-600 flex-shrink-0 mt-0.5"></i>
                        <div>
                            <p class="text-xs font-bold text-emerald-600 uppercase tracking-wider mb-0.5">Action Plan</p>
                            <p class="text-sm text-emerald-900 leading-relaxed font-bold">${action}</p>
                        </div>
                    </div>
                    <div class="flex gap-2.5 items-start bg-blue-50/50 p-3 rounded-xl border border-blue-100/50">
                        <i data-lucide="calculator" class="w-4 h-4 text-blue-600 flex-shrink-0 mt-0.5"></i>
                        <div>
                            <p class="text-xs font-bold text-blue-600 uppercase tracking-wider mb-0.5">The Math</p>
                            <p class="text-sm text-blue-900 leading-relaxed font-bold">${numbers}</p>
                        </div>
                    </div>
                </div>
            `;
            chatBox.insertBefore(div, typingIndicator);
            lucide.createIcons();
            scrollToBottom();
        }

        // Render Error Bubble
        function appendErrorMessage(msg) {
            const div = document.createElement('div');
            div.className = 'flex gap-3 chat-bubble-enter';
            div.innerHTML = `
                <div class="w-8 h-8 rounded-full bg-red-100 flex-shrink-0 flex items-center justify-center border border-red-200 mt-1">
                    <i data-lucide="alert-triangle" class="w-4 h-4 text-red-600"></i>
                </div>
                <div class="bg-red-50 p-4 rounded-2xl rounded-tl-none border border-red-100 shadow-sm max-w-[85%] sm:max-w-[70%]">
                    <p class="text-sm text-red-700 font-bold leading-relaxed">${msg}</p>
                </div>
            `;
            chatBox.insertBefore(div, typingIndicator);
            lucide.createIcons();
            scrollToBottom();
        }

        // Submit Logic
        chatForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const text = chatInput.value.trim();
            if(!text) return;

            // UI Updates
            chatInput.value = '';
            chatInput.style.height = ''; // reset height
            sendBtn.disabled = true;
            document.getElementById('quickActions').classList.add('hidden'); // hide quick actions after first message
            
            appendUserMessage(text);
            
            // Show typing indicator
            typingIndicator.classList.remove('hidden');
            scrollToBottom();

            const formData = new FormData();
            formData.append('action', 'send_chat');
            formData.append('message', text);

            try {
                const response = await fetch('advisor.php', { method: 'POST', body: formData });
                const res = await response.json();

                typingIndicator.classList.add('hidden');

                if (res.error) {
                    appendErrorMessage(res.error);
                } else if (res.success && res.data) {
                    appendAIMessage(res.data);
                }
            } catch (err) {
                typingIndicator.classList.add('hidden');
                appendErrorMessage("Network error. Please check your connection and try again.");
            } finally {
                sendBtn.disabled = false;
                chatInput.focus();
            }
        });
    </script>
</body>
</html>