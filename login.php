<?php
   session_start();
   $host='localhost';
   $db='finorax';
   $user='root';
   $pass='';
   $charset='utf8mb4';
   $dsn="mysql:host=  $host  ;dbname=  $db  ;charset=  $charset";
   $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,];
   if ($_SERVER['REQUEST_METHOD']==='POST') {
       header('Content-Type: application/json');
       try {
           $pdo=new PDO($dsn,$user,$pass,$options);
           $email=trim($_POST['email']??'');
           $password=$_POST['password']??'';
           if (empty($email)||empty($password)) {
               echo json_encode(['status'=>'error','message'=>'Please enter both email and password.']);
               exit;
           }
           $stmt=$pdo->prepare("SELECT user_id, password FROM users WHERE email = ?");
           $stmt->execute([$email]);
           $userRecord=$stmt->fetch();
           if ($userRecord&&password_verify($password,$userRecord['password'])) {
               $_SESSION['user_id']=$userRecord['user_id'];
               echo json_encode(['status'=>'success','message'=>'Login successful!']);
           }
           else {
               echo json_encode(['status'=>'error','message'=>'Invalid email or password.']);
           }
       }
       catch (\PDOException $e) {
           echo json_encode(['status'=>'error','message'=>'System Error: '.$e->getMessage()]);
       }
       exit;
   }
   
   ?> <!doctype html>
<html lang="en">
   <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Login | FinoraX</title>
      <script src="https://cdn.tailwindcss.com/3.4.17"></script> <script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script> 
      <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
      <style>
         :root {
         --primary-deep: #0A2540;
         --accent-gold: #C9A962;
         }
         body {
         font-family: 'DM Sans',sans-serif;
         background-color: var(--primary-deep);
         margin: 0;
         min-height: 100vh;
         }
         .heading-font {
         font-family: 'Playfair Display',serif;
         }
         .pattern-overlay {
         position: fixed;
         inset: 0;
         opacity: 0.05;
         background-image: repeating-linear-gradient(45deg,transparent,transparent 35px,rgba(255,255,255,0.5) 35px,rgba(255,255,255,0.5) 70px),repeating-linear-gradient(-45deg,transparent,transparent 35px,rgba(255,255,255,0.3) 35px,rgba(255,255,255,0.3) 70px);
         pointer-events: none;
         z-index: 0;
         }
         .form-card {
         background: #FFFFFF;
         box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
         z-index: 10;
         }
         .input-field {
         background: #F8FAFC;
         border: 1px solid #E8ECF0;
         transition: all 0.2s ease;
         }
         .input-field:focus {
         border-color: var(--accent-gold);
         outline: none;
         box-shadow: 0 0 0 3px rgba(201,169,98,0.1);
         }
         #error-msg {
         display: none;
         background: #fee2e2;
         color: #991b1b;
         padding: 10px;
         border-radius: 8px;
         font-size: 0.875rem;
         margin-bottom: 1rem;
         text-align: center;
         border: 1px solid #f87171;
         }
      </style>
   </head>
   <body class="flex items-center justify-center p-6">
      <div class="pattern-overlay"></div>
      <div class="form-card w-full max-w-md rounded-2xl p-8 md:p-10 relative">
         <div class="flex flex-col items-center mb-8">
            <div class="w-14 h-14 bg-[#0A2540] rounded-2xl flex items-center justify-center mb-4 shadow-lg"><i data-lucide="shield-check" class="w-8 h-8 text-white"></i></div>
            <h1 class="heading-font text-3xl font-bold text-slate-900">FinoraX</h1>
            <p class="text-sm text-slate-500 mt-1">Sign in to your account</p>
         </div>
         <div id="error-msg"></div>
         <form id="loginForm" class="space-y-4">
            <div class="relative"><i data-lucide="mail" class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400"></i><input type="email" name="email" placeholder="Email Address" class="input-field w-full h-12 pl-12 pr-4 rounded-xl text-sm" required></div>
            <div class="relative"><i data-lucide="lock" class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400"></i><input type="password" id="passInput" name="password" placeholder="Password" class="input-field w-full h-12 pl-12 pr-12 rounded-xl text-sm" required><button type="button" id="togglePass" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"><i data-lucide="eye" class="w-5 h-5"></i></button></div>
            <button type="submit" id="submitBtn" class="w-full h-12 rounded-xl bg-[#0A2540] text-white font-bold text-sm mt-4 hover:bg-[#1A3A5C] transition-all transform hover:-translate-y-0.5 shadow-lg"> Sign In </button>
         </form>
         <div class="mt-8 text-center">
            <p class="text-sm text-slate-500">Don't have an account? <a href="register.php" class="text-[#C9A962] font-semibold hover:underline">Register</a></p>
         </div>
      </div>
      <script>
         lucide.createIcons();
         const togglePass=document.getElementById('togglePass');
         const passInput=document.getElementById('passInput');
         togglePass.addEventListener('click',() =>  {
             const type=passInput.type  == = 'password'?'text':'password';
             passInput.type=type;
             togglePass.innerHTML=type  == = 'text'?'<i data-lucide="eye-off" class="w-5 h-5"></i>':'<i data-lucide="eye" class="w-5 h-5"></i>';
             lucide.createIcons();
         }
         );
         const loginForm=document.getElementById('loginForm');
         const submitBtn=document.getElementById('submitBtn');
         const errorMsg=document.getElementById('error-msg');
         loginForm.addEventListener('submit',async(e) =>  {
             e.preventDefault();
             errorMsg.style.display='none';
             submitBtn.disabled=true;
             submitBtn.innerHTML='Signing in...';
             const formData=new FormData(loginForm);
             try {
                 const response=await fetch('', {
                     method:'POST',body:formData
                 }
                 );
                 const result=await response.json();
                 if (result.status  == = 'success') {
                     window.location.href='onboarding.php';
                 }
                 else {
                     errorMsg.textContent=result.message;
                     errorMsg.style.display='block';
                     submitBtn.disabled=false;
                     submitBtn.textContent='Sign In';
                 }
             }
             catch (err) {
                 errorMsg.textContent="Connection Error. Please try again.";
                 errorMsg.style.display='block';
                 submitBtn.disabled=false;
                 submitBtn.textContent='Sign In';
             }
         }
         );
      </script> 
   </body>
</html>