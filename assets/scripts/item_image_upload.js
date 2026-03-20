const fileUpload = document.getElementById('file-upload');
const previewContainer = document.getElementById('imagePreview');
const attachedStatus = document.getElementById('attachedStatus');
const placeholder = document.getElementById('uploadPlaceholder');
const fileNameDisplay = document.getElementById('fileNameDisplay');
const dropZone = document.getElementById('dropZone');
const previewImg = previewContainer.querySelector('img');

// Handle File Selection
fileUpload.onchange = function () {
    const [file] = this.files;
    if (file) {
        // Update UI to "Attached" state
        fileNameDisplay.textContent = file.name;
        attachedStatus.classList.remove('hidden');
        dropZone.classList.add('border-green-400', 'bg-green-50/30');
        dropZone.classList.remove('border-slate-200');

        // Show actual image preview
        previewImg.src = URL.createObjectURL(file);
        previewContainer.classList.remove('hidden');
    }
};

// Reset everything
function clearPreview() {
    fileUpload.value = '';
    previewContainer.classList.add('hidden');
    attachedStatus.classList.add('hidden');
    dropZone.classList.remove('border-green-400', 'bg-green-50/30');
    dropZone.classList.add('border-slate-200');
    placeholder.classList.remove('opacity-0');
}

// Duplicate Check Logic
const titleInput = document.getElementById('itemTitle');
const alertBox = document.getElementById('duplicateAlert');
titleInput.addEventListener('input', (e) => {
    const value = e.target.value.toLowerCase();
    const keywords = ['wallet', 'phone', 'keys', 'id'];
    if (value.length > 3 && keywords.some(k => value.includes(k))) {
        alertBox.classList.remove('hidden');
    } else {
        alertBox.classList.add('hidden');
    }
});