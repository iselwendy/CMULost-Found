const modal = document.getElementById('itemModal');
const modalCard = document.getElementById('modalCard');

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
        document.getElementById('modalClaimLink').href = `item_details.php?id=${item.id}&type=found`;
        foundAction.classList.remove('hidden');
    } else {
        document.getElementById('modalFoundLink').href = `report_found.php`;
        lostAction.classList.remove('hidden');
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