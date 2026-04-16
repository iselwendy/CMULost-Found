const fileUpload = document.getElementById('file-upload');
const previewContainer = document.getElementById('imagePreview');
const attachedStatus = document.getElementById('attachedStatus');
const placeholder = document.getElementById('uploadPlaceholder');
const fileNameDisplay = document.getElementById('fileNameDisplay');
const dropZone = document.getElementById('dropZone');
const previewImg = previewContainer ? previewContainer.querySelector('img') : null;

if (fileUpload) {
    fileUpload.onchange = function () {
        const [file] = this.files;
        if (file) {
            if (fileNameDisplay) fileNameDisplay.textContent = file.name;
            if (attachedStatus) attachedStatus.classList.remove('hidden');
            if (dropZone) {
                dropZone.classList.add('border-green-400', 'bg-green-50/30');
                dropZone.classList.remove('border-slate-200');
            }
            if (previewImg) previewImg.src = URL.createObjectURL(file);
            if (previewContainer) previewContainer.classList.remove('hidden');
        }
    };
}

function clearPreview() {
    if (fileUpload) fileUpload.value = '';
    if (previewContainer) previewContainer.classList.add('hidden');
    if (attachedStatus) attachedStatus.classList.add('hidden');
    if (dropZone) {
        dropZone.classList.remove('border-green-400', 'bg-green-50/30');
        dropZone.classList.add('border-slate-200');
    }
    if (placeholder) placeholder.classList.remove('opacity-0');
}

let dupDebounceTimer = null;

const titleInput = document.getElementById('itemTitle');
const alertBox = document.getElementById('duplicateAlert');
const dupList = document.getElementById('duplicateList');

if (titleInput && alertBox) {
    titleInput.addEventListener('input', function () {
        const value = this.value.trim();
        clearTimeout(dupDebounceTimer);

        if (value.length < 4) {
            alertBox.classList.add('hidden');
            return;
        }

        dupDebounceTimer = setTimeout(() => checkDuplicates(value), 900);
    });
}

async function checkDuplicates(title) {
    const categoryEl = document.getElementById('itemCategory');
    const reportTypeEl = document.querySelector('input[name="report_type"]');
    const category_id = getCategoryId(categoryEl ? categoryEl.value : '');
    const report_type = reportTypeEl ? reportTypeEl.value : 'lost';

    try {
        const res = await fetch('../core/duplicate_check.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title, category_id, report_type }),
        });
        const data = await res.json();

        if (!data.success || !data.items || data.items.length === 0) {
            alertBox.classList.add('hidden');
            return;
        }

        dupList.innerHTML = data.items.map(item => {
            const date = item.created_at
                ? new Date(item.created_at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
                : '';

            // Build the URL: index.php opens with the right view tab, and a hash
            // that index.php can read on load to auto-open the modal.
            const params = new URLSearchParams({
                view: item.type === 'found' ? 'found' : 'lost',
                open_id: item.report_id,
                open_type: item.type,
            });
            const url = `../public/index.php?${params.toString()}`;

            return `
                <a href="${url}" target="_blank" rel="noopener"
                class="flex items-center justify-between gap-2 mt-1 px-2 py-1.5 rounded-lg
                        hover:bg-amber-100 transition text-amber-800 group">
                    <span>
                        &bull; <strong>${escHtml(item.title)}</strong>
                        ${item.category ? `<span class="opacity-70">· ${escHtml(item.category)}</span>` : ''}
                        ${date ? `<span class="opacity-70">· ${date}</span>` : ''}
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-amber-400 text-[10px] opacity-0
                            group-hover:opacity-100 transition flex-shrink-0"></i>
                </a>`;
        }).join('');

        alertBox.classList.remove('hidden');

    } catch (e) {
        alertBox.classList.add('hidden');
    }
}

function getCategoryId(categoryName) {
    const map = {
        'Electronics': 1,
        'Valuables': 2,
        'Documents': 3,
        'Books': 4,
        'Clothing': 5,
        'Personal': 6,
        'Other': 7,
    };
    return map[categoryName] || 0;
}

function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}