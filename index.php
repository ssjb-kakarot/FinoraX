<?php
// index.php - Finora X Homepage
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finora X | AI Financial Mentor</title>
    <meta name="description" content="Track, plan, and grow your money with Finora X - Your AI Financial Mentor.">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;500;600;700&family=Playfair+Display:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Tailwind Config -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#0A2540',
                        accent: '#c9a962',
                        accentHover: '#b59756',
                    },
                    fontFamily: {
                        sans: ['"DM Sans"', 'sans-serif'],
                        serif: ['"Playfair Display"', 'serif'],
                    },
                    boxShadow: {
                        'soft': '0 20px 40px -15px rgba(10, 37, 64, 0.08)',
                        'float': '0 25px 50px -12px rgba(10, 37, 64, 0.15)',
                    }
                }
            }
        }
    </script>
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="font-sans text-gray-800 bg-slate-50 antialiased selection:bg-accent selection:text-primary">

    <!-- NAVBAR -->
    <nav class="sticky top-0 z-50 bg-white/80 backdrop-blur-lg border-b border-gray-100 transition-all duration-300">
        <div class="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
            <!-- Left: Logo -->
            <a href="index.php" class="flex items-center gap-2 group">
                <div class="bg-primary p-2 rounded-xl group-hover:bg-accent transition-colors duration-300">
                    <i data-lucide="landmark" class="w-6 h-6 text-white group-hover:text-primary transition-colors"></i>
                </div>
                <span class="font-serif font-bold text-2xl text-primary tracking-tight">Finora X</span>
            </a>
            
            <!-- Right: Links -->
            <div class="flex items-center gap-6">
                <a href="login.php" class="hidden md:block font-medium text-gray-600 hover:text-primary transition-colors">Login</a>
                <a href="register.php" class="bg-accent text-primary px-6 py-2.5 rounded-full font-bold hover:bg-accentHover hover:-translate-y-0.5 shadow-lg shadow-accent/30 transition-all duration-300">
                    Get Started
                </a>
            </div>
        </div>
    </nav>

    <!-- 1. HERO SECTION -->
    <section class="relative pt-20 pb-24 lg:pt-32 lg:pb-32 px-6 overflow-hidden">
        <!-- Background Blur Decor -->
        <div class="absolute top-0 right-0 -z-10 w-96 h-96 bg-accent/10 rounded-full blur-3xl translate-x-1/2 -translate-y-1/2"></div>
        <div class="absolute bottom-0 left-0 -z-10 w-96 h-96 bg-primary/5 rounded-full blur-3xl -translate-x-1/2 translate-y-1/2"></div>
        
        <div class="max-w-7xl mx-auto grid lg:grid-cols-2 gap-16 items-center">
            <!-- Left Content -->
            <div class="max-w-2xl">
                <div class="inline-flex items-center gap-2 bg-accent/10 border border-accent/20 text-primary px-4 py-1.5 rounded-full text-sm font-bold mb-8">
                    <i data-lucide="sparkles" class="w-4 h-4 text-accent"></i>
                    Replace your ₹25,000/year financial advisor with AI
                </div>
                
                <h1 class="font-serif text-5xl lg:text-6xl font-bold text-primary leading-[1.15] mb-6">
                    Your AI Financial Mentor for Smarter Money Decisions
                </h1>
                
                <p class="text-xl text-gray-600 mb-10 leading-relaxed">
                    Track, plan, and grow your money — without expensive advisors. Take full control of your financial future today.
                </p>
                
                <div class="flex flex-col sm:flex-row gap-4">
                    <a href="register.php" class="flex items-center justify-center gap-2 bg-accent text-primary px-8 py-4 rounded-full font-bold text-lg hover:bg-accentHover hover:-translate-y-1 shadow-xl shadow-accent/20 transition-all duration-300">
                        Get Started Free
                        <i data-lucide="arrow-right" class="w-5 h-5"></i>
                    </a>
                    <a href="login.php" class="flex items-center justify-center gap-2 bg-white text-primary border border-gray-200 px-8 py-4 rounded-full font-bold text-lg hover:bg-gray-50 hover:border-gray-300 transition-all duration-300">
                        Login
                    </a>
                </div>
            </div>
            
            <!-- Right Mockup -->
            <div class="relative lg:ml-auto w-full max-w-md perspective-1000">
                <div class="bg-white rounded-3xl p-8 shadow-float border border-gray-100 transform rotate-y-[-5deg] rotate-x-[5deg] hover:rotate-y-0 hover:rotate-x-0 transition-transform duration-700">
                    
                    <div class="flex justify-between items-center mb-8">
                        <div>
                            <p class="text-sm font-medium text-gray-500 mb-1">Total Balance</p>
                            <h3 class="font-serif text-3xl font-bold text-primary">₹68,000</h3>
                        </div>
                        <div class="bg-green-100 text-green-700 p-2 rounded-xl">
                            <i data-lucide="trending-up" class="w-6 h-6"></i>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <!-- Income Bar -->
                        <div>
                            <div class="flex justify-between text-sm mb-2">
                                <span class="font-medium text-gray-600 flex items-center gap-2">
                                    <div class="w-2 h-2 rounded-full bg-primary"></div> Income
                                </span>
                                <span class="font-bold text-primary">₹50,000</span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-2.5">
                                <div class="bg-primary h-2.5 rounded-full" style="width: 100%"></div>
                            </div>
                        </div>
                        
                        <!-- Expenses Bar -->
                        <div>
                            <div class="flex justify-between text-sm mb-2">
                                <span class="font-medium text-gray-600 flex items-center gap-2">
                                    <div class="w-2 h-2 rounded-full bg-red-400"></div> Expenses
                                </span>
                                <span class="font-bold text-primary">₹32,000</span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-2.5">
                                <div class="bg-red-400 h-2.5 rounded-full" style="width: 64%"></div>
                            </div>
                        </div>

                        <!-- Savings Bar -->
                        <div>
                            <div class="flex justify-between text-sm mb-2">
                                <span class="font-medium text-gray-600 flex items-center gap-2">
                                    <div class="w-2 h-2 rounded-full bg-accent"></div> Savings
                                </span>
                                <span class="font-bold text-primary">₹18,000</span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-2.5">
                                <div class="bg-accent h-2.5 rounded-full" style="width: 36%"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-8 pt-6 border-t border-gray-100">
                        <div class="flex items-center gap-4 bg-primary/5 p-4 rounded-2xl">
                            <div class="bg-primary text-white p-2 rounded-full">
                                <i data-lucide="bot" class="w-5 h-5"></i>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-primary">AI Insight</p>
                                <p class="text-xs text-gray-600">You saved 12% more than last month. Keep it up!</p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </section>

    <!-- 2. PROBLEM SECTION -->
    <section class="py-24 px-6 bg-white">
        <div class="max-w-7xl mx-auto text-center">
            <h2 class="font-serif text-4xl md:text-5xl font-bold text-primary mb-16">Why Most People Struggle with Money</h2>
            
            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-8">
                <!-- Card 1 -->
                <div class="bg-slate-50 p-8 rounded-3xl border border-gray-100 hover:shadow-soft hover:-translate-y-1 transition-all duration-300">
                    <div class="bg-red-100 w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <i data-lucide="map-x" class="w-7 h-7 text-red-600"></i>
                    </div>
                    <h3 class="font-serif text-xl font-bold text-primary mb-3">No clear plan</h3>
                    <p class="text-gray-600">Living paycheck to paycheck without a roadmap for the future.</p>
                </div>
                
                <!-- Card 2 -->
                <div class="bg-slate-50 p-8 rounded-3xl border border-gray-100 hover:shadow-soft hover:-translate-y-1 transition-all duration-300">
                    <div class="bg-red-100 w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <i data-lucide="receipt" class="w-7 h-7 text-red-600"></i>
                    </div>
                    <h3 class="font-serif text-xl font-bold text-primary mb-3">Money disappears</h3>
                    <p class="text-gray-600">Wondering where your income went by the end of every month.</p>
                </div>

                <!-- Card 3 -->
                <div class="bg-slate-50 p-8 rounded-3xl border border-gray-100 hover:shadow-soft hover:-translate-y-1 transition-all duration-300">
                    <div class="bg-red-100 w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <i data-lucide="line-chart" class="w-7 h-7 text-red-600"></i>
                    </div>
                    <h3 class="font-serif text-xl font-bold text-primary mb-3">Investing is confusing</h3>
                    <p class="text-gray-600">Too much jargon makes building wealth feel impossible.</p>
                </div>

                <!-- Card 4 -->
                <div class="bg-slate-50 p-8 rounded-3xl border border-gray-100 hover:shadow-soft hover:-translate-y-1 transition-all duration-300">
                    <div class="bg-red-100 w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <i data-lucide="users-2" class="w-7 h-7 text-red-600"></i>
                    </div>
                    <h3 class="font-serif text-xl font-bold text-primary mb-3">Advisors are costly</h3>
                    <p class="text-gray-600">Traditional wealth managers charge hefty fees just to talk.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 3. SOLUTION SECTION -->
    <section class="py-24 px-6 bg-slate-50">
        <div class="max-w-7xl mx-auto">
            <div class="text-center mb-16">
                <h2 class="font-serif text-4xl md:text-5xl font-bold text-primary mb-4">Finora X Fixes This</h2>
                <p class="text-lg text-gray-600 max-w-2xl mx-auto">Everything you need to master your personal finances in one beautiful, easy-to-use platform.</p>
            </div>
            
            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-8">
                <!-- Feature 1 -->
                <div class="group bg-white p-8 rounded-3xl border border-gray-100 hover:shadow-soft transition-all duration-300 cursor-default">
                    <div class="bg-primary/5 w-14 h-14 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 group-hover:bg-primary transition-all duration-300">
                        <i data-lucide="layout-dashboard" class="w-7 h-7 text-primary group-hover:text-accent transition-colors"></i>
                    </div>
                    <h3 class="font-serif text-xl font-bold text-primary mb-3">Smart Dashboard</h3>
                    <p class="text-gray-600">See your entire financial life clearly in one beautiful interface.</p>
                </div>

                <!-- Feature 2 -->
                <div class="group bg-white p-8 rounded-3xl border border-gray-100 hover:shadow-soft transition-all duration-300 cursor-default">
                    <div class="bg-primary/5 w-14 h-14 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 group-hover:bg-primary transition-all duration-300">
                        <i data-lucide="target" class="w-7 h-7 text-primary group-hover:text-accent transition-colors"></i>
                    </div>
                    <h3 class="font-serif text-xl font-bold text-primary mb-3">Goal Planner</h3>
                    <p class="text-gray-600">Turn big dreams into actionable, step-by-step monthly plans.</p>
                </div>

                <!-- Feature 3 -->
                <div class="group bg-white p-8 rounded-3xl border border-gray-100 hover:shadow-soft transition-all duration-300 cursor-default">
                    <div class="bg-primary/5 w-14 h-14 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 group-hover:bg-primary transition-all duration-300">
                        <i data-lucide="pie-chart" class="w-7 h-7 text-primary group-hover:text-accent transition-colors"></i>
                    </div>
                    <h3 class="font-serif text-xl font-bold text-primary mb-3">Expense Tracking</h3>
                    <p class="text-gray-600">Understand exactly where every single rupee you spend is going.</p>
                </div>

                <!-- Feature 4 -->
                <div class="group bg-white p-8 rounded-3xl border border-gray-100 hover:shadow-soft transition-all duration-300 cursor-default">
                    <div class="bg-primary/5 w-14 h-14 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 group-hover:bg-primary transition-all duration-300">
                        <i data-lucide="brain-circuit" class="w-7 h-7 text-primary group-hover:text-accent transition-colors"></i>
                    </div>
                    <h3 class="font-serif text-xl font-bold text-primary mb-3">AI Advisor</h3>
                    <p class="text-gray-600">Get instant, personalized financial guidance tailored just for you.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 4. HOW IT WORKS -->
    <section class="py-24 px-6 bg-white border-t border-gray-100">
        <div class="max-w-7xl mx-auto">
            <h2 class="font-serif text-4xl md:text-5xl font-bold text-primary text-center mb-20">How It Works</h2>
            
            <div class="grid md:grid-cols-4 gap-8 relative">
                <!-- Decorative Line -->
                <div class="hidden md:block absolute top-8 left-[10%] right-[10%] h-0.5 bg-gray-100 -z-10"></div>
                
                <!-- Step 1 -->
                <div class="text-center relative">
                    <div class="w-16 h-16 mx-auto bg-primary text-accent rounded-full flex items-center justify-center font-serif text-2xl font-bold mb-6 shadow-lg shadow-primary/20">1</div>
                    <h3 class="font-bold text-primary text-xl mb-3">Add Details</h3>
                    <p class="text-gray-600">Securely input your basic financial details to build your profile.</p>
                </div>

                <!-- Step 2 -->
                <div class="text-center relative">
                    <div class="w-16 h-16 mx-auto bg-primary text-accent rounded-full flex items-center justify-center font-serif text-2xl font-bold mb-6 shadow-lg shadow-primary/20">2</div>
                    <h3 class="font-bold text-primary text-xl mb-3">Track Flow</h3>
                    <p class="text-gray-600">Log your income and expenses to create an accurate cash flow picture.</p>
                </div>

                <!-- Step 3 -->
                <div class="text-center relative">
                    <div class="w-16 h-16 mx-auto bg-primary text-accent rounded-full flex items-center justify-center font-serif text-2xl font-bold mb-6 shadow-lg shadow-primary/20">3</div>
                    <h3 class="font-bold text-primary text-xl mb-3">Get Insights</h3>
                    <p class="text-gray-600">Our AI analyzes your habits and builds a robust financial plan.</p>
                </div>

                <!-- Step 4 -->
                <div class="text-center relative">
                    <div class="w-16 h-16 mx-auto bg-primary text-accent rounded-full flex items-center justify-center font-serif text-2xl font-bold mb-6 shadow-lg shadow-primary/20">4</div>
                    <h3 class="font-bold text-primary text-xl mb-3">Grow Wealth</h3>
                    <p class="text-gray-600">Hit your targets, invest smartly, and watch your net worth grow.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 5. FEATURE STRIP -->
    <section class="bg-primary py-8 px-6 overflow-hidden">
        <div class="max-w-7xl mx-auto flex flex-wrap justify-center items-center gap-x-12 gap-y-6">
            <div class="flex items-center gap-3 text-white">
                <i data-lucide="check-circle-2" class="w-6 h-6 text-accent"></i>
                <span class="font-medium text-lg">Know your savings rate instantly</span>
            </div>
            <div class="flex items-center gap-3 text-white">
                <i data-lucide="check-circle-2" class="w-6 h-6 text-accent"></i>
                <span class="font-medium text-lg">Stop overspending without realizing</span>
            </div>
            <div class="flex items-center gap-3 text-white">
                <i data-lucide="check-circle-2" class="w-6 h-6 text-accent"></i>
                <span class="font-medium text-lg">Plan your future clearly</span>
            </div>
            <div class="flex items-center gap-3 text-white">
                <i data-lucide="check-circle-2" class="w-6 h-6 text-accent"></i>
                <span class="font-medium text-lg">Make smarter money decisions</span>
            </div>
        </div>
    </section>

    <!-- 6. SOCIAL PROOF -->
    <section class="py-24 px-6 bg-slate-50">
        <div class="max-w-7xl mx-auto text-center">
            <h2 class="font-medium text-accent tracking-widest uppercase mb-4">The New Standard</h2>
            <h3 class="font-serif text-3xl md:text-4xl font-bold text-primary mb-16">Built for modern Indian investors</h3>
            
            <div class="grid md:grid-cols-3 gap-8">
                <!-- Stat 1 -->
                <div class="bg-white p-10 rounded-3xl border border-gray-100 shadow-sm">
                    <h4 class="font-serif text-5xl font-bold text-primary mb-2">₹0+</h4>
                    <p class="text-gray-500 font-medium uppercase tracking-wide text-sm">Wealth Tracked</p>
                </div>
                
                <!-- Stat 2 -->
                <div class="bg-white p-10 rounded-3xl border border-gray-100 shadow-sm">
                    <h4 class="font-serif text-5xl font-bold text-primary mb-2">0+</h4>
                    <p class="text-gray-500 font-medium uppercase tracking-wide text-sm">Active Users</p>
                </div>
                
                <!-- Stat 3 -->
                <div class="bg-white p-10 rounded-3xl border border-gray-100 shadow-sm">
                    <h4 class="font-serif text-5xl font-bold text-primary mb-2">0+</h4>
                    <p class="text-gray-500 font-medium uppercase tracking-wide text-sm">Goals Planned</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 7. FINAL CTA SECTION -->
    <section class="px-4 pb-24 lg:px-8">
        <div class="max-w-7xl mx-auto bg-primary rounded-[2.5rem] py-24 px-6 text-center shadow-2xl relative overflow-hidden border border-primary/20">
            <!-- Background Decoration -->
            <div class="absolute top-0 right-0 w-64 h-64 bg-accent/20 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2"></div>
            <div class="absolute bottom-0 left-0 w-64 h-64 bg-white/5 rounded-full blur-3xl translate-y-1/2 -translate-x-1/2"></div>
            
            <div class="relative z-10 max-w-3xl mx-auto">
                <h2 class="font-serif text-4xl md:text-6xl font-bold text-white mb-6 leading-tight">
                    Start Taking Control of Your Money Today
                </h2>
                <p class="text-xl text-gray-300 mb-10">
                    It takes less than 2 minutes to get started and set up your financial dashboard.
                </p>
                <a href="register.php" class="inline-flex items-center justify-center gap-2 bg-accent text-primary px-10 py-5 rounded-full font-bold text-xl hover:bg-accentHover hover:scale-105 shadow-[0_0_40px_rgba(201,169,98,0.4)] transition-all duration-300">
                    Create Free Account
                    <i data-lucide="arrow-right" class="w-6 h-6"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- 8. FOOTER -->
    <footer class="bg-white border-t border-gray-100 pt-16 pb-8 px-6">
        <div class="max-w-7xl mx-auto flex flex-col md:flex-row justify-between items-center gap-6">
            <!-- Logo -->
            <div class="flex items-center gap-2">
                <i data-lucide="landmark" class="w-6 h-6 text-primary"></i>
                <span class="font-serif font-bold text-2xl text-primary tracking-tight">Finora X</span>
            </div>
            
            <!-- Links -->
            <div class="flex gap-8">
                <a href="login.php" class="font-medium text-gray-500 hover:text-primary transition-colors">Login</a>
                <a href="register.php" class="font-medium text-gray-500 hover:text-primary transition-colors">Register</a>
            </div>
        </div>
        
        <div class="max-w-7xl mx-auto mt-12 pt-8 border-t border-gray-50 text-center text-gray-400 text-sm">
            &copy; <?php echo date('Y'); ?> Finora X. All rights reserved.
        </div>
    </footer>

    <!-- Initialize Lucide Icons -->
    <script>
        lucide.createIcons();
    </script>
</body>
</html>