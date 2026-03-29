# Finorax PHP Project — XAMPP Setup & Usage Guide 🚀

This guide is specifically designed for running the **Finorax PHP Project on XAMPP (Localhost)**. Follow each step carefully and you’ll be up and running in minutes.

---

## 📦 Requirements

Make sure you have:

* XAMPP (latest version recommended)
* PHP 7.4+ (comes with XAMPP)
* MySQL (via XAMPP)
* Web browser (Chrome/Edge/etc.)

---

## 📁 Step 1: Place Project in XAMPP

1. Open your XAMPP installation folder
   Example:

   ```
   C:\xampp\htdocs\
   ```

2. Copy your project folder into `htdocs`

Final structure:

```
htdocs/
└── finorax/
    ├── index.php
    ├── config.php
    ├── register.php
    ├── finorax.sql
    └── other files...
```

---

## ▶️ Step 2: Start XAMPP Services

1. Open **XAMPP Control Panel**
2. Start:

   * ✅ Apache
   * ✅ MySQL

Both should turn green

---

## 🗄️ Step 3: Create Database

1. Open browser:

   ```
   http://localhost/phpmyadmin
   ```

2. Click **New**

3. Create database:

   ```
   finorax
   ```

4. Set collation:

   ```
   utf8mb4_general_ci
   ```

---

## 📥 Step 4: Import Database

1. Click on `finorax` database
2. Go to **Import**
3. Upload:

   ```
   finorax.sql
   ```
4. Click **Go**

✔️ This will create all tables automatically

---

## ⚙️ Step 5: Configure Database

Open `config.php` and ensure settings match:

```php
$host = 'localhost';
$db   = 'finorax';
$user = 'root';
$pass = '';
```

👉 Default XAMPP uses:

* username: `root`
* password: *(empty)*

---

## 🔑 Step 6: Add API Keys

Replace placeholder keys in `config.php`:

```php
$GEMINI_API_KEY = [
    "YOUR_API_KEY_1",
    "YOUR_API_KEY_2"
];
```

---

## 🌐 Step 7: Run the Project

Open your browser and visit:

```
http://localhost/finorax/index.php
```

---

## 🚀 How to Use the Application

### 🟢 First Time Users

1. Visit:

   ```
   http://localhost/finorax/index.php
   ```

2. Click on **Get Started** OR directly go to:

   ```
   http://localhost/finorax/register.php
   ```

3. Create a new account by filling in required details

4. After registration:

   * Log in using your credentials

---

### 🔐 After Login

Once logged in, you can:

* Access dashboard features
* Use application tools/modules
* Perform actions as per system workflow

👉 Simply follow on-screen navigation and options to continue using the system.

---

## ⚠️ Common Issues & Fixes

### ❌ Apache Not Starting

* Close Skype / other apps using port 80
* Or change Apache port in XAMPP config

---

### ❌ MySQL Not Starting

* Kill existing MySQL processes
* Check port conflicts (3306)

---

### ❌ Database Connection Error

* Re-check:

  * DB name = `finorax`
  * user = `root`
  * password = empty

---

### ❌ Blank Page / Errors Hidden

Enable errors temporarily:

```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

---

### ❌ Tables Not Found

* Ensure `finorax.sql` was imported correctly

---

## 🔒 Security Note (Local Only)

This setup is for **local development only**.
Before going live:

* Use strong database passwords
* Hide error messages
* Secure API keys

---

## ✅ Done!

Your Finorax system is now fully working on XAMPP 🎉

---

## 💬 Need Extras?

Contact for Help ankitsarkar120706@gmail.com