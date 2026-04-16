const modal = document.getElementById('itemModal');
const modalCard = document.getElementById('modalCard');

// Build a query string from an object, skipping null/undefined values
function buildQuery(params) {
    return Object.entries(params)
        .filter(([, v]) => v !== null && v !== undefined && v !== '')
        .map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v))
        .join('&');
}

function openModal(item) {
    // Populate fields
    document.getElementById('modalImage').src = item.image_path;
    document.getElementById('modalTitle').textContent = item.item_name;
    document.getElementById('modalCategory').textContent = item.category;
    document.getElementById('modalLocation').textContent = item.location;
    document.getElementById('modalDateLabel').textContent = item.date_label;
    document.getElementById('modalDate').textContent = item.date;
    document.getElementById('modalStatus').textContent = item.status;

    // Type badge
    const badge = document.getElementById('modalTypeBadge');
    badge.textContent = item.type;
    badge.className = badge.className.replace(/text-(green|red)-600/g, '');
    badge.classList.add(item.type === 'found' ? 'text-green-600' : 'text-red-600');

    // Show correct action button
    const foundAction = document.getElementById('modalActionFound');
    const lostAction = document.getElementById('modalActionLost');
    foundAction.classList.add('hidden');
    lostAction.classList.add('hidden');

    if (item.type === 'found') {
        const claimableStatuses = ['in custody', 'surrendered', 'matched'];
        if (claimableStatuses.includes(item.status.toLowerCase())) {
            // Pre-fill report_lost.php with public details of the found item
            const query = buildQuery({
                prefill: 1,
                found_id: item.id,
                title: item.item_name,
                category: item.category,
                location: item.location,
                date: item.raw_date,   // ISO date for datetime-local input
            });
            document.getElementById('modalClaimLink').href = `report_lost.php?${query}`;
            foundAction.classList.remove('hidden');
        } else {
            foundAction.innerHTML = `
                <p class="text-center text-sm font-semibold text-gray-400 py-3 bg-gray-50 rounded-xl border border-gray-100">
                    <i class="fas fa-check-circle mr-2 text-green-400"></i>
                    This item has already been ${item.status}.
                </p>`;
            foundAction.classList.remove('hidden');
        }
    } else {
        // Lost item — "I Found This Item" prefills report_found.php
        const activeStatuses = ['open', 'matched'];
        if (activeStatuses.includes(item.status.toLowerCase())) {
            const query = buildQuery({
                prefill: 1,
                lost_id: item.id,
                title: item.item_name,
                category: item.category,
                location: item.location,
                date: item.raw_date,
            });
            document.getElementById('modalFoundLink').href = `report_found.php?${query}`;
            lostAction.classList.remove('hidden');
        } else {
            lostAction.innerHTML = `
                <p class="text-center text-sm font-semibold text-gray-400 py-3 bg-gray-50 rounded-xl border border-gray-100">
                    <i class="fas fa-check-circle mr-2 text-green-400"></i>
                    This report has been ${item.status}.
                </p>`;
            lostAction.classList.remove('hidden');
        }
    }

    // Animate in
    modal.classList.remove('opacity-0', 'pointer-events-none');
    modal.classList.add('opacity-100');
    document.body.classList.add('modal-open');
    requestAnimationFrame(() => {
        modalCard.classList.remove('scale-95', 'opacity-0');
        modalCard.classList.add('scale-100', 'opacity-100');
    });
}

function closeModal() {
    modalCard.classList.remove('scale-100', 'opacity-100');
    modalCard.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0', 'pointer-events-none');
        document.body.classList.remove('modal-open');

        // Reset action buttons back to original HTML so they're reusable
        document.getElementById('modalActionFound').innerHTML = `
            <a id="modalClaimLink" href="#"
               class="block w-full text-center py-3.5 bg-cmu-gold text-cmu-blue font-black rounded-xl hover:shadow-lg transition text-sm uppercase tracking-wide">
                <i class="fas fa-hand-holding-heart mr-2"></i> This Is Mine — Claim Item
            </a>
            <p class="text-center text-[10px] text-gray-400 mt-2">
                You will need to visit OSA and present valid ID for verification.
            </p>`;
        document.getElementById('modalActionLost').innerHTML = `
            <a id="modalFoundLink" href="#"
               class="block w-full text-center py-3.5 bg-cmu-blue text-white font-black rounded-xl hover:bg-slate-800 transition text-sm uppercase tracking-wide">
                <i class="fas fa-search mr-2"></i> I Found This Item
            </a>`;
    }, 200);
}

// Close when clicking the backdrop
modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
});

// Close on Escape key
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();
});