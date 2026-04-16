<?php
/**
 * CMU Lost & Found — Legal Modal
 * 
 * Usage: Include this file anywhere you need the Terms & Privacy modal.
 * 
 *   <?php require_once 'path/to/legal.php'; ?>
 * 
 * To open the modal from any link/button:
 *   <a href="#" onclick="openLegalModal(); return false;">Terms & Privacy Policy</a>
 *   <button onclick="openLegalModal()">Terms & Privacy Policy</button>
 */
?>

<!-- ── Legal Modal Overlay ──────────────────────────────────────────────── -->
<div id="legalModal"
     class="fixed inset-0 z-[200] flex items-center justify-center p-4
            bg-slate-900/70 backdrop-blur-sm
            opacity-0 pointer-events-none"
     style="transition: opacity 0.25s ease;"
     onclick="if(event.target===this) closeLegalModal()">

    <div id="legalModalCard"
         class="bg-white rounded-3xl w-full max-w-2xl max-h-[90vh]
                shadow-2xl flex flex-col overflow-hidden
                scale-95 opacity-0"
         style="transition: transform 0.25s ease, opacity 0.25s ease;">

        <!-- Modal Header -->
        <div class="bg-cmu-blue px-8 py-6 flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-white/15 rounded-xl flex items-center justify-center">
                    <i class="fas fa-shield-alt text-white"></i>
                </div>
                <div>
                    <h2 class="text-white font-black text-base uppercase tracking-widest leading-none">
                        Terms &amp; Privacy Policy
                    </h2>
                    <p class="text-blue-200 text-[10px] mt-0.5 font-semibold uppercase tracking-wider">
                        Last updated: January 2026
                    </p>
                </div>
            </div>
            <button onclick="closeLegalModal()"
                    class="w-9 h-9 flex items-center justify-center rounded-full
                           bg-white/10 text-white hover:bg-white/20 transition text-sm"
                    aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Modal Body (scrollable) -->
        <div class="overflow-y-auto flex-1 px-8 py-8 space-y-10
                    [&::-webkit-scrollbar]:w-1.5
                    [&::-webkit-scrollbar-thumb]:rounded-full
                    [&::-webkit-scrollbar-thumb]:bg-slate-200">

            <!-- Section 1: Terms of Use -->
            <section>
                <div class="flex items-center gap-3 mb-4">
                    <span class="w-8 h-8 rounded-lg bg-blue-100 text-cmu-blue flex items-center
                                 justify-center text-sm font-black flex-shrink-0">1</span>
                    <h3 class="text-xl font-bold text-slate-900">Terms and Conditions</h3>
                </div>

                <div class="space-y-4 text-slate-600 text-sm leading-relaxed pl-11">
                    <p>
                        By using the CMU Lost &amp; Found portal, you agree to provide truthful information.
                        Reporting items you do not possess or claiming items that are not yours is a violation
                        of the Student Code of Conduct and may result in disciplinary action.
                    </p>

                    <!-- Disposal policy callout -->
                    <div class="bg-amber-50 border-l-4 border-amber-400 p-4 rounded-r-xl">
                        <h4 class="font-bold text-amber-900 text-xs uppercase tracking-wide mb-1">
                            <i class="fas fa-clock mr-1"></i> Disposal Policy
                        </h4>
                        <p class="text-amber-800 text-sm">
                            In accordance with SAO regulations, found items surrendered to the office will be held
                            for a maximum of <strong>sixty (60) calendar days</strong>. Unclaimed items after this
                            period will be subject to disposal or university donation.
                        </p>
                    </div>

                    <p>
                        All users are expected to act in good faith. Misuse of the platform — including submitting
                        fraudulent reports or attempting to claim items without proof of ownership — may be
                        escalated to the Office of Student Affairs for appropriate action.
                    </p>
                </div>
            </section>

            <!-- Divider -->
            <hr class="border-slate-100">

            <!-- Section 2: Privacy Policy -->
            <section>
                <div class="flex items-center gap-3 mb-4">
                    <span class="w-8 h-8 rounded-lg bg-blue-100 text-cmu-blue flex items-center
                                 justify-center text-sm font-black flex-shrink-0">2</span>
                    <h3 class="text-xl font-bold text-slate-900">Privacy Policy</h3>
                </div>

                <div class="space-y-4 text-slate-600 text-sm leading-relaxed pl-11">
                    <p>
                        We collect your University Email, Full Name, and School Number to ensure
                        accountability across reports. Item photos and private descriptions are also
                        stored for the purpose of ownership verification.
                    </p>

                    <ul class="space-y-3">
                        <li class="flex items-start gap-3">
                            <i class="fas fa-eye-slash text-cmu-blue mt-0.5 flex-shrink-0 text-xs"></i>
                            <div>
                                <strong class="text-slate-800">Data Visibility:</strong>
                                Your contact details and private item descriptions are hidden from the public
                                gallery. Only SAO Admin personnel can access this information for
                                verification purposes.
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="fas fa-bell text-cmu-blue mt-0.5 flex-shrink-0 text-xs"></i>
                            <div>
                                <strong class="text-slate-800">Email Notifications:</strong>
                                By reporting a lost item, you consent to receive automated email alerts
                                when a potential match is found by our matching engine.
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="fas fa-database text-cmu-blue mt-0.5 flex-shrink-0 text-xs"></i>
                            <div>
                                <strong class="text-slate-800">Data Retention:</strong>
                                Records of resolved cases are archived for one academic year for auditing
                                and dispute-resolution purposes. After this period, personal data may be
                                anonymized or deleted per university records policy.
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="fas fa-robot text-cmu-blue mt-0.5 flex-shrink-0 text-xs"></i>
                            <div>
                                <strong class="text-slate-800">AI Matching:</strong>
                                Item descriptions you provide are processed by an automated matching engine
                                to identify potential connections between lost and found reports. No data is
                                shared with third-party services without your consent.
                            </div>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Footer note -->
            <div class="pt-2 border-t border-slate-100 text-center">
                <p class="text-xs text-slate-400 italic">
                    For legal inquiries or data removal requests, contact the
                    <strong class="text-slate-500">Office of Student Affairs (SAO)</strong>.
                </p>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="px-8 py-5 border-t border-slate-100 flex items-center justify-between
                    bg-slate-50/60 flex-shrink-0">
            <p class="text-[11px] text-slate-400">
                <i class="fas fa-info-circle mr-1"></i>
                Continued use of this portal implies acceptance of these terms.
            </p>
            <button onclick="closeLegalModal()"
                    class="px-6 py-2.5 bg-cmu-blue text-white rounded-xl font-bold text-sm
                           hover:bg-slate-800 transition shadow-sm">
                I Understand
            </button>
        </div>
    </div>
</div>

<!-- ── Legal Modal Scripts ──────────────────────────────────────────────── -->
<script>
    function openLegalModal() {
        const modal = document.getElementById('legalModal');
        const card  = document.getElementById('legalModalCard');

        modal.classList.remove('opacity-0', 'pointer-events-none');
        modal.classList.add('opacity-100');
        document.body.style.overflow = 'hidden';

        requestAnimationFrame(() => {
            card.classList.remove('scale-95', 'opacity-0');
            card.classList.add('scale-100', 'opacity-100');
        });
    }

    function closeLegalModal() {
        const modal = document.getElementById('legalModal');
        const card  = document.getElementById('legalModalCard');

        card.classList.remove('scale-100', 'opacity-100');
        card.classList.add('scale-95', 'opacity-0');

        setTimeout(() => {
            modal.classList.add('opacity-0', 'pointer-events-none');
            modal.classList.remove('opacity-100');
            document.body.style.overflow = '';
        }, 250);
    }

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('legalModal');
            if (modal && !modal.classList.contains('pointer-events-none')) {
                closeLegalModal();
            }
        }
    });
</script>