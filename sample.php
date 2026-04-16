<!-- SAO VERIFY MODAL -->
<div id="sao-verify-modal" class="fixed inset-0 z-[100] hidden overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 sm:p-0">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeSaoModal()"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

        <div class="inline-block align-bottom bg-white rounded-3xl overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full border border-slate-200">

            <!-- Header -->
            <div class="bg-cmu-blue px-8 py-6 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-white/15 rounded-xl flex items-center justify-center">
                        <i class="fas fa-user-check text-white text-sm"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-blue-300 uppercase tracking-widest">Student Affairs Office</p>
                        <h3 class="text-white font-black text-base uppercase tracking-widest leading-none mt-0.5">Claiming Instructions</h3>
                    </div>
                </div>
                <button onclick="closeSaoModal()" class="w-9 h-9 flex items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 transition text-sm">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Matched item card -->
            <div class="px-8 py-6 border-b border-slate-100">
                <div class="flex gap-5 items-start bg-blue-50/60 border border-blue-100 rounded-2xl p-4">
                    <div class="w-20 h-20 rounded-xl bg-white border border-slate-200 overflow-hidden flex-shrink-0 flex items-center justify-center text-slate-300">
                        <img id="sao-item-image" src="" alt="Item" class="w-full h-full object-cover hidden">
                        <div id="sao-image-placeholder" class="flex items-center justify-center w-full h-full">
                            <i class="fas fa-image text-3xl"></i>
                        </div>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-blue-500 uppercase tracking-widest mb-1">Matched Item</p>
                        <h4 id="sao-item-title" class="text-xl font-black text-slate-800 mb-2">—</h4>
                        <p class="text-xs text-slate-500 italic leading-relaxed">
                            "If this looks like your missing item, follow the steps below to complete the recovery."
                        </p>
                    </div>
                </div>
            </div>

            <!-- Steps -->
            <div class="px-8 py-6">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">How to claim your item</p>

                <div class="divide-y divide-slate-100">
                    <?php
                    $steps = [
                        ['num' => 1, 'title' => 'Visit the SAO Office',           'body' => 'Go to the Office of Student Affairs during office hours (Mon–Fri, 8:00 AM – 5:00 PM).'],
                        ['num' => 2, 'title' => 'Present valid identification',    'body' => 'Bring your CMU Student ID or any government-issued ID for identity verification.'],
                        ['num' => 3, 'title' => 'Verify ownership',                'body' => 'Be prepared to describe unique markings, passwords (for gadgets), or contents that prove the item is yours.'],
                    ];
                    foreach ($steps as $s): ?>
                    <div class="flex gap-4 py-4">
                        <div class="w-9 h-9 bg-cmu-blue text-cmu-gold rounded-full flex items-center justify-center flex-shrink-0 text-xs font-black">
                            <?php echo $s['num']; ?>
                        </div>
                        <div class="pt-0.5">
                            <p class="text-sm font-bold text-slate-800"><?php echo $s['title']; ?></p>
                            <p class="text-xs text-slate-500 mt-1 leading-relaxed"><?php echo $s['body']; ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Warning notice -->
                <div class="bg-amber-50 border border-amber-100 rounded-2xl p-4 flex gap-3 mt-4">
                    <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5 flex-shrink-0 text-sm"></i>
                    <p class="text-[11px] text-amber-800 leading-relaxed">
                        Items are held for a maximum of <strong>60 days</strong>. If unclaimed, they will be donated or disposed of per university policy. Please visit OSA as soon as possible.
                    </p>
                </div>
            </div>

            <!-- Footer -->
            <div class="px-8 py-4 bg-slate-50/50 border-t border-slate-100 flex justify-end">
                <button onclick="closeSaoModal()" class="px-6 py-2.5 bg-cmu-blue text-white rounded-xl text-sm font-bold hover:bg-slate-800 transition shadow-sm">
                    Understood
                </button>
            </div>

        </div>
    </div>
</div>