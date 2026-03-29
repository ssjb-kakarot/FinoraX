<?php
 include 'auth.php';
include 'config.php';
$user_id=$_SESSION['user_id']??null;
$stmt=$pdo->prepare("SELECT name FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user=$stmt->fetch(PDO::FETCH_ASSOC);
$firstName=$user?explode(' ',trim($user['name']))[0]:'User';
$stmt=$pdo->prepare("SELECT * FROM financial_profiles WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$user_id]);
$profile=$stmt->fetch(PDO::FETCH_ASSOC);
if (!$profile) {
    $profile=['monthly_income'=>0,'other_income'=>0,'total_expenses'=>0,'savings'=>0,'investments'=>0,'debt'=>0,'insurance'=>0,'savings_rate'=>0,'emergency_fund'=>0,'invest_regularly'=>0];
}
$totalIncome=$profile['monthly_income']+$profile['other_income'];
$totalExpenses=$profile['total_expenses'];
$netSavings=$totalIncome-$totalExpenses;
$assets=$profile['savings']+$profile['investments'];
$liabilities=$profile['debt'];
$netWorth=$assets-$liabilities;
$stmt=$pdo->prepare("SELECT category, SUM(amount) as total FROM transactions WHERE user_id = ? AND type = 'DEBIT' GROUP BY category ORDER BY total DESC");
$stmt->execute([$user_id]);
$categoryExpenses=$stmt->fetchAll(PDO::FETCH_ASSOC);
$actualTotalDebit=array_sum(array_column($categoryExpenses,'total'));
$safeTotalDebit=$actualTotalDebit>0?$actualTotalDebit:1;
$topCategory=$categoryExpenses[0]['category']??'None';
$topCategoryAmount=$categoryExpenses[0]['total']??0;
$stmt=$pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY date DESC LIMIT 10");
$stmt->execute([$user_id]);
$recentTransactions=$stmt->fetchAll(PDO::FETCH_ASSOC);
$healthScore=0;
$savingsRate=$profile['savings_rate'];
if ($savingsRate>=20)$healthScore+=30;
elseif ($savingsRate>=10)$healthScore+=15;
elseif ($savingsRate>0)$healthScore+=5;
$annualIncome=$totalIncome*12;
if ($liabilities==0) {
    $healthScore+=30;
}
else {
    $debtRatio=$annualIncome>0?($liabilities/$annualIncome):1;
    if ($debtRatio<0.2)$healthScore+=25;
    elseif ($debtRatio<0.4)$healthScore+=15;
    elseif ($debtRatio<0.6)$healthScore+=5;
}
if ($profile['investments']>0||$profile['invest_regularly']==1) {
    $healthScore+=20;
}
if ($profile['insurance']>0)$healthScore+=10;
if ($profile['emergency_fund']==1)$healthScore+=10;
$healthScore=min(100,max(0,$healthScore));
if ($healthScore>=80) {
    $healthStatus='Excellent';
    $healthColor='text-emerald-500';
    $healthBg='bg-emerald-50 border-emerald-100';
}
elseif ($healthScore>=60) {
    $healthStatus='Good';
    $healthColor='text-blue-500';
    $healthBg='bg-blue-50 border-blue-100';
}
elseif ($healthScore>=40) {
    $healthStatus='Fair';
    $healthColor='text-amber-500';
    $healthBg='bg-amber-50 border-amber-100';
}
else {
    $healthStatus='Needs Work';
    $healthColor='text-red-500';
    $healthBg='bg-red-50 border-red-100';
}
$insights=[];
if ($savingsRate<10) {
    $insights[]=['type'=>'amber','icon'=>'alert-triangle','title'=>'Low Savings Rate','msg'=>'You are saving less than 10% of your income. Try to reduce discretionary spending.'];
}
if ($annualIncome>0&&($liabilities/$annualIncome)>0.5) {
    $insights[]=['type'=>'red','icon'=>'trending-down','title'=>'High Debt Burden','msg'=>'Your debt exceeds 50% of your annual income. Focus on high-interest loans.'];
}
if ($profile['insurance']==0) {
    $insights[]=['type'=>'blue','icon'=>'shield-alert','title'=>'Missing Insurance','msg'=>'No insurance detected. Health & life insurance protect your net worth.'];
}
if ($actualTotalDebit>0&&($topCategoryAmount/$actualTotalDebit)>0.35) {
    $pct=round(($topCategoryAmount/$actualTotalDebit)*100);
    $insights[]=['type'=>'amber','icon'=>'pie-chart','title'=>'Heavy Spending Area','msg'=>"You're spending  {
        $pct
    }
    % of your expenses on  {
        $topCategory
    }
    ."];
}
if (empty($insights)) {
    $insights[]=['type'=>'emerald','icon'=>'check-circle','title'=>'On Track!','msg'=>'Your financial fundamentals look strong. Keep compounding your wealth.'];
}

?>
<html lang="en">
   <head>
      <meta charset="UTF-8">
      <meta content="width=device-width,initial-scale=1"name="viewport">
      <title>Dashboard | Finora X</title>
      <script src="https://cdn.tailwindcss.com"></script><script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script>
      <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap"rel="stylesheet">
      <style>:root{--primary:#0a2540;--accent:#c9a962}body{font-family:'DM Sans',sans-serif}.heading-font{font-family:'Playfair Display',serif}html{scroll-behavior:smooth}.custom-scrollbar::-webkit-scrollbar{width:6px}.custom-scrollbar::-webkit-scrollbar-track{background:#f1f5f9}.custom-scrollbar::-webkit-scrollbar-thumb:hover{background:#94a3b8}</style>
   </head>
   <body class="flex bg-slate-50 overflow-hidden antialiased h-screen text-slate-900">
      <?php include 'sidebar.php'; ?>
      <div class="flex flex-col flex-1 bg-slate-50 min-w-0 overflow-hidden">
         <header class="flex items-center justify-between z-10 bg-white border-b border-slate-100 flex-shrink-0 h-16 px-4 shadow-sm sm:px-6">
            <button class="-ml-2 focus:outline-none hover:bg-slate-100 hover:text-[#0a2540] p-2 rounded-lg text-slate-500 transition-colors"aria-label="Toggle sidebar"id="menu-toggle"><i class="h-6 w-6"data-lucide="menu"></i></button>
            <div class="flex items-center gap-4 ml-auto">
               <div class="text-right hidden mr-2 sm:block">
                  <p class="font-bold uppercase tracking-wider text-slate-500 text-xs">Welcome back</p>
                  <p class="font-bold text-sm text-[#0a2540]"><?=htmlspecialchars($firstName)?></p>
               </div>
               <div class="flex items-center justify-center rounded-full h-10 w-10 bg-[#0a2540] border-2 border-[#c9a962] font-bold heading-font shadow-md text-white"><?=strtoupper(substr($firstName,0,1))?></div>
            </div>
         </header>
         <main class="p-4 custom-scrollbar flex-1 lg:p-8 overflow-y-auto sm:p-6">
            <div class="max-w-7xl mx-auto sm:space-y-8 space-y-6">
               <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                  <div>
                     <h1 class="font-bold text-[#0a2540] heading-font sm:text-4xl text-3xl">Financial Overview</h1>
                     <p class="font-medium mt-1 text-slate-500">Here is your automated financial snapshot.</p>
                  </div>
                  <button class="items-center justify-center bg-[#0a2540] font-bold text-white border border-[#1e3a5f] gap-2 hover:bg-[#153457] inline-flex px-5 py-2.5 rounded-xl shadow-lg sm:text-base text-sm transition-colors"><i class="h-4 w-4 text-[#c9a962]"data-lucide="plus-circle"></i> Add Transaction</button>
               </div>
               <div class="grid grid-cols-1 gap-5 lg:grid-cols-4 sm:grid-cols-2">
                  <div class="overflow-hidden p-6 relative rounded-2xl bg-gradient-to-br from-[#0a2540] group shadow-xl to-[#1e3a5f]">
                     <div class="transform -mr-4 -mt-4 absolute bg-white duration-500 group-hover:scale-150 h-24 opacity-5 right-0 rounded-full top-0 transition-transform w-24"></div>
                     <div class="flex items-center justify-between mb-4 relative z-10">
                        <span class="font-bold text-sm tracking-wider uppercase text-slate-300">Net Worth</span>
                        <div class="flex items-center justify-center rounded-full h-8 w-8 backdrop-blur-sm bg-white/10"><i class="h-4 w-4 text-[#c9a962]"data-lucide="globe"></i></div>
                     </div>
                     <h2 class="font-bold text-white relative text-3xl z-10">₹<?=number_format($netWorth)?></h2>
                     <div class="flex items-center relative z-10 font-medium gap-1 mt-2 text-emerald-400 text-sm">Assets - Liabilities</div>
                  </div>
                  <div class="flex flex-col bg-white border border-slate-100 p-6 rounded-2xl shadow-sm hover:shadow-md justify-between transition-shadow">
                     <div class="flex items-center justify-between mb-4">
                        <span class="font-bold text-sm tracking-wider uppercase text-slate-500">Monthly Income</span>
                        <div class="flex items-center justify-center rounded-full h-8 w-8 bg-emerald-50"><i class="h-4 w-4 text-emerald-600"data-lucide="arrow-up-right"></i></div>
                     </div>
                     <h2 class="font-bold text-[#0a2540] text-2xl">₹<?=number_format($totalIncome)?></h2>
                     <p class="text-sm font-medium mt-1 text-slate-400">From all sources</p>
                  </div>
                  <div class="flex flex-col bg-white border border-slate-100 p-6 rounded-2xl shadow-sm hover:shadow-md justify-between transition-shadow">
                     <div class="flex items-center justify-between mb-4">
                        <span class="font-bold text-sm tracking-wider uppercase text-slate-500">Monthly Expenses</span>
                        <div class="flex items-center justify-center rounded-full h-8 w-8 bg-red-50"><i class="h-4 w-4 text-red-600"data-lucide="arrow-down-right"></i></div>
                     </div>
                     <h2 class="font-bold text-[#0a2540] text-2xl">₹<?=number_format($totalExpenses)?></h2>
                     <p class="text-sm font-medium mt-1 text-slate-400">Across all categories</p>
                  </div>
                  <div class="flex flex-col bg-white border border-slate-100 p-6 rounded-2xl shadow-sm hover:shadow-md justify-between transition-shadow">
                     <div class="flex items-center justify-between mb-4">
                        <span class="font-bold text-sm tracking-wider uppercase text-slate-500">Net Savings</span>
                        <div class="flex items-center justify-center rounded-full h-8 w-8 bg-blue-50"><i class="h-4 w-4 text-blue-600"data-lucide="piggy-bank"></i></div>
                     </div>
                     <h2 class="font-bold text-[#0a2540] text-2xl">₹<?=number_format($netSavings)?></h2>
                     <p class="text-sm font-medium mt-1 text-slate-400">Rate:<?=number_format($savingsRate,1)?>%</p>
                  </div>
               </div>
               <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                  <div class="flex flex-col bg-white border border-slate-100 p-6 rounded-2xl shadow-sm sm:p-8 lg:col-span-2">
                     <div class="flex items-center justify-between mb-6">
                        <h2 class="flex items-center font-bold gap-2 text-[#0a2540] text-lg"><i class="text-[#c9a962] h-5 w-5"data-lucide="bar-chart-2"></i> Top Spending Categories</h2>
                        <button class="font-bold text-sm hover:text-blue-800 text-blue-600">View All</button>
                     </div>
                     <?php if(empty($categoryExpenses)): ?>
                     <div class="flex items-center justify-center flex-col flex-1 text-slate-400 py-10">
                        <i class="opacity-50 h-12 mb-3 w-12"data-lucide="inbox"></i>
                        <p class="font-medium">No recent expenses to show.</p>
                     </div>
                     <?php else: ?>
                     <div class="flex flex-col justify-center flex-1 space-y-5">
                        <?php $colors=['bg-[#0a2540]','bg-[#1e3a5f]','bg-[#3b5d82]','bg-[#6487ab]','bg-[#94a3b8]'];$limit=min(count($categoryExpenses),5);for($i=0;$i<$limit;$i++):$cat=$categoryExpenses[$i];$pct=($cat['total']/$safeTotalDebit)*100;$bgClass=$colors[$i]?? $colors[4]; ?>
                        <div>
                           <div class="flex justify-between font-bold mb-1.5 text-sm"><span class="text-xs uppercase text-slate-700 tracking-wide"><?=htmlspecialchars($cat['category'])?></span><span class="text-[#0a2540]">₹<?=number_format($cat['total'])?><span class="font-medium text-slate-400 ml-1">(<?=round($pct)?>%)</span></span></div>
                           <div class="overflow-hidden bg-slate-100 h-2.5 rounded-full w-full">
                              <div class="<?=$bgClass?>h-2.5 rounded-full"style="width:<?=$pct?>%"></div>
                           </div>
                        </div>
                        <?php endfor; ?>
                     </div>
                     <?php endif; ?>
                  </div>
                  <div class="flex items-center justify-center flex-col bg-white border border-slate-100 lg:col-span-1 overflow-hidden p-6 relative rounded-2xl shadow-sm sm:p-8 text-center">
                     <h2 class="flex items-center font-bold gap-2 text-[#0a2540] text-lg mb-6 text-left w-full"><i class="text-[#c9a962] h-5 w-5"data-lucide="activity"></i> Money Health</h2>
                     <div class="flex items-center justify-center relative h-40 mb-4 w-40">
                        <svg class="transform -rotate-90 h-full w-full">
                           <circle class="text-slate-100"cx="80"cy="80"fill="transparent"r="70"stroke="currentColor"stroke-width="12"/>
                           <?php $circumference=2*pi()*70;$offset=$circumference-($circumference*$healthScore/100); ?>
                           <circle class="duration-1000 ease-out<?=$healthColor?>transition-all"cx="80"cy="80"fill="transparent"r="70"stroke="currentColor"stroke-width="12"stroke-dasharray="<?=$circumference?>"stroke-dashoffset="<?=$offset?>"stroke-linecap="round"/>
                        </svg>
                        <div class="flex items-center justify-center flex-col absolute"><span class="font-bold text-[#0a2540] text-4xl"><?=$healthScore?></span><span class="font-bold uppercase text-xs text-slate-400 tracking-widest">Score</span></div>
                     </div>
                     <div class="items-center inline-flex px-4 py-1.5 rounded-full border text-sm font-bold<?=$healthBg?><?=$healthColor?>"><?=$healthStatus?></div>
                     <p class="text-sm font-medium leading-relaxed mt-4 text-slate-500">Based on your savings rate, debt ratio, investments, and insurance setup.</p>
                  </div>
               </div>
               <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 pb-10">
                  <div class="flex flex-col bg-white border border-slate-100 p-6 rounded-2xl shadow-sm sm:p-8">
                     <h2 class="flex items-center font-bold gap-2 text-[#0a2540] text-lg mb-6"><i class="text-[#c9a962] h-5 w-5"data-lucide="lightbulb"></i> Smart Insights</h2>
                     <div class="space-y-4 flex-1">
                        <?php foreach($insights as $insight):$bg="bg-{$insight['type']}-50";$border="border-{$insight['type']}-100";$iconColor="text-{$insight['type']}-600";$titleColor="text-{$insight['type']}-900"; ?>
                        <div class="flex p-4 gap-4 rounded-xl border<?=$bg?><?=$border?>">
                           <div class="flex-shrink-0 mt-0.5"><i class="w-5 h-5<?=$iconColor?>"data-lucide="<?=$insight['icon']?>"></i></div>
                           <div>
                              <h4 class="font-bold text-sm<?=$titleColor?>"><?=$insight['title']?></h4>
                              <p class="text-sm font-medium mt-1 text-slate-600"><?=$insight['msg']?></p>
                           </div>
                        </div>
                        <?php endforeach; ?>
                     </div>
                  </div>
                  <div class="flex flex-col bg-white border border-slate-100 p-6 rounded-2xl shadow-sm sm:p-8">
                     <div class="flex items-center justify-between mb-6">
                        <h2 class="flex items-center font-bold gap-2 text-[#0a2540] text-lg"><i class="text-[#c9a962] h-5 w-5"data-lucide="list"></i> Recent Transactions</h2>
                        <button class="font-bold text-sm hover:text-blue-800 text-blue-600">History</button>
                     </div>
                     <?php if(empty($recentTransactions)): ?>
                     <div class="flex items-center justify-center flex-col flex-1 text-slate-400 py-6">
                        <i class="opacity-50 h-10 mb-2 w-10"data-lucide="credit-card"></i>
                        <p class="text-sm font-medium">No transactions available.</p>
                     </div>
                     <?php else: ?>
                     <div class="space-y-1">
                        <?php foreach($recentTransactions as $tx):$isCredit=$tx['type']==='CREDIT';$icon=$isCredit?'arrow-down-left':'arrow-up-right';$iconBg=$isCredit?'bg-emerald-100 text-emerald-600':'bg-slate-100 text-slate-600';$amountColor=$isCredit?'text-emerald-600':'text-[#0a2540]';$sign=$isCredit?'+':'-';$dateStr=$tx['date']?date('M d, Y',strtotime($tx['date'])):'Unknown Date'; ?>
                        <div class="flex items-center justify-between cursor-pointer hover:bg-slate-50 p-3 rounded-xl transition">
                           <div class="flex items-center gap-4">
                              <div class="flex items-center justify-center rounded-full h-10 w-10 flex-shrink-0<?=$iconBg?>"><i class="h-4 w-4"data-lucide="<?=$icon?>"></i></div>
                              <div>
                                 <p class="font-bold text-sm sm:w-48 text-slate-800 truncate w-32"><?=htmlspecialchars($tx['description'])?></p>
                                 <p class="font-medium text-slate-400 mt-0.5 text-xs"><?=$dateStr?>•<?=htmlspecialchars($tx['category']?? 'Other')?></p>
                              </div>
                           </div>
                           <div class="text-right">
                              <p class="font-bold text-sm<?=$amountColor?>"><?=$sign?>₹<?=number_format($tx['amount'],2)?></p>
                           </div>
                        </div>
                        <?php endforeach; ?>
                     </div>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
         </main>
      </div>
      <script>// Initialize Lucide Icons
         lucide.createIcons();
         
         // Sidebar Toggle Logic
         document.addEventListener('DOMContentLoaded', () => {
             const menuToggle = document.getElementById('menu-toggle');
             if(menuToggle) {
                 menuToggle.addEventListener('click', () => {
                     if (typeof window.toggleSidebar === 'function') {
                         window.toggleSidebar();
                     }
                 });
             }
         });
      </script>
   </body>
</html>