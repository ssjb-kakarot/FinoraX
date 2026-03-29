<?php
// Start the session at the very beginning to ensure we can store variables
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include the external configuration file
require_once 'config.php';

/**
 * Generates a unique user_id: FX-123456
 */
function generateUniqueUserID($pdo) {
    $exists = true;
    $newID = "";
    while ($exists) {
        $newID = 'FX-' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
        $stmt->execute([$newID]);
        if (!$stmt->fetch()) {
            $exists = false;
        }
    }
    return $newID;
}

// Handle AJAX Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        // $pdo is now automatically available from config.php
        
        $fullName = trim($_POST['fullName'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $dob      = $_POST['dob'] ?? '';

        if (empty($fullName) || empty($phone) || empty($email) || empty($password) || empty($dob)) {
            echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
            exit;
        }

        // Email Duplicate Check
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'This email is already registered.']);
            exit;
        }

        $userId = generateUniqueUserID($pdo);
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (user_id, name, phone_number, email, password, dob) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute([$userId, $fullName, $phone, $email, $hashedPassword, $dob])) {
            
            // ---> CREATE SESSION HERE <---
            // Store the FX-XXXXXX ID in the session. 
            // Note: If your system relies on the database's auto-incrementing `id` instead, 
            // simply change this to: $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['user_id'] = $userId;
            
            echo json_encode(['status' => 'success', 'message' => 'Account created!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database insertion failed.']);
        }
    } catch (\PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'System Error: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | FinoraX</title>
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-deep: #0a2540;
            --accent-gold: #c9a962;
        }
        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--primary-deep);
            margin: 0;
            min-height: 100vh;
        }
        .heading-font {
            font-family: 'Playfair Display', serif;
        }
        .pattern-overlay {
            position: fixed;
            inset: 0;
            opacity: .05;
            background-image: repeating-linear-gradient(45deg, transparent, transparent 35px, rgba(255,255,255,0.5) 35px, rgba(255,255,255,0.5) 70px), 
                              repeating-linear-gradient(-45deg, transparent, transparent 35px, rgba(255,255,255,0.3) 35px, rgba(255,255,255,0.3) 70px);
            pointer-events: none;
            z-index: 0;
        }
        .form-card {
            background: #fff;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
            z-index: 10;
        }
        .input-field {
            background: #f8fafc;
            border: 1px solid #e8ecf0;
            transition: all .2s ease;
        }
        .input-field:focus {
            border-color: var(--accent-gold);
            outline: 0;
            box-shadow: 0 0 0 3px rgba(201,169,98,0.1);
        }
        #success-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 100;
            background: rgba(10,37,64,0.9);
            backdrop-filter: blur(12px);
            align-items: center;
            justify-content: center;
        }
        #error-msg {
            display: none;
            background: #fee2e2;
            color: #991b1b;
            padding: 10px;
            border-radius: 8px;
            font-size: .875rem;
            margin-bottom: 1rem;
            text-align: center;
            border: 1px solid #f87171;
        }
    </style>
</head>
<body class="flex items-center justify-center p-6">
    <div class="pattern-overlay"></div>
    
    <!-- Success Modal -->
    <div id="success-modal">
        <div class="bg-white rounded-3xl p-8 max-w-sm w-full mx-4 text-center shadow-2xl scale-100 transition-transform">
            <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <i data-lucide="check-circle" class="w-10 h-10 text-green-600"></i>
            </div>
            <h2 class="heading-font text-2xl font-bold mb-2 text-slate-900">Registration Successful!</h2>
            <p class="text-sm mb-8 text-slate-500">Your Account Creation Is Successful. Please Proceed With Onboarding Process.</p>
            <div class="space-y-4">
                <button onclick="window.location.href='onboarding.php'" class="w-full h-12 rounded-xl bg-[#0A2540] text-white font-semibold text-sm hover:opacity-90 transition-opacity">
                    Go to Onboarding Now
                </button>
                <p class="text-xs text-slate-400">Auto-redirecting in <span id="countdown" class="font-bold">3</span>s</p>
            </div>
        </div>
    </div>

    <!-- Registration Form -->
    <div class="form-card w-full max-w-md rounded-2xl p-8 md:p-10 relative">
        <div class="flex flex-col items-center mb-8">
            <div class="w-14 h-14 bg-[#0A2540] rounded-2xl flex items-center justify-center mb-4 shadow-lg">
                <i data-lucide="landmark" class="w-8 h-8 text-white"></i>
            </div>
            <h1 class="heading-font text-3xl font-bold text-slate-900">FinoraX</h1>
            <p class="text-sm text-slate-500 mt-1">Create your elite financial account</p>
        </div>
        
        <div id="error-msg"></div>
        
        <form id="regForm" class="space-y-4">
            <div class="relative">
                <i data-lucide="user" class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400"></i>
                <input type="text" name="fullName" placeholder="Full Name" class="input-field w-full h-12 pl-12 pr-4 rounded-xl text-sm" required>
            </div>
            <div class="relative">
                <i data-lucide="phone" class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400"></i>
                <input type="tel" name="phone" placeholder="Phone Number" class="input-field w-full h-12 pl-12 pr-4 rounded-xl text-sm" required>
            </div>
            <div class="relative">
                <i data-lucide="mail" class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400"></i>
                <input type="email" name="email" placeholder="Email Address" class="input-field w-full h-12 pl-12 pr-4 rounded-xl text-sm" required>
            </div>
            <div class="relative">
                <i data-lucide="lock" class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400"></i>
                <input type="password" id="passInput" name="password" placeholder="Create Password" class="input-field w-full h-12 pl-12 pr-12 rounded-xl text-sm" required>
                <button type="button" id="togglePass" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                    <i data-lucide="eye" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="relative">
                <i data-lucide="calendar" class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400"></i>
                <input type="date" name="dob" class="input-field w-full h-12 pl-12 pr-4 rounded-xl text-sm" required>
            </div>
            <button type="submit" id="submitBtn" class="w-full h-12 rounded-xl bg-[#0A2540] text-white font-bold text-sm mt-4 hover:bg-[#1A3A5C] transition-all transform hover:-translate-y-0.5 shadow-lg">
                Create Account
            </button>
        </form>
        
        <div class="mt-8 text-center">
            <p class="text-sm text-slate-500">Already have an account? <a href="login.php" class="text-[#C9A962] font-semibold hover:underline">Login</a></p>
        </div>
    </div>

    <script>
        lucide.createIcons();
        
        const togglePass = document.getElementById('togglePass');
        const passInput = document.getElementById('passInput');
        
        togglePass.addEventListener('click', () => {
            const type = passInput.type === 'password' ? 'text' : 'password';
            passInput.type = type;
            togglePass.innerHTML = type === 'text' ? '<i data-lucide="eye-off" class="w-5 h-5"></i>' : '<i data-lucide="eye" class="w-5 h-5"></i>';
            lucide.createIcons();
        });
        
        const regForm = document.getElementById('regForm');
        const submitBtn = document.getElementById('submitBtn');
        const errorMsg = document.getElementById('error-msg');
        const successModal = document.getElementById('success-modal');
        const countdownEl = document.getElementById('countdown');
        
        regForm.addEventListener('submit', async(e) => {
            e.preventDefault();
            errorMsg.style.display = 'none';
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="flex items-center justify-center gap-2"><svg class="animate-spin h-5 w-5 text-white" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Creating...</span>';
            
            const formData = new FormData(regForm);
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if(result.status === 'success'){
                    successModal.style.display = 'flex';
                    let count = 3;
                    const timer = setInterval(() => {
                        count--;
                        countdownEl.textContent = count;
                        if(count <= 0){
                            clearInterval(timer);
                            window.location.href = 'onboarding.php';
                        }
                    }, 1000);
                } else {
                    errorMsg.textContent = result.message;
                    errorMsg.style.display = 'block';
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Create Account';
                }
            } catch(err) {
                errorMsg.textContent = "Connection Error. Please try again.";
                errorMsg.style.display = 'block';
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create Account';
            }
        });
    </script>
</body>
</html>