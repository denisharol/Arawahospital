<!-- templates/modal.php -->
<!-- Generic Modal Template - Include where needed -->

<div id="<?php echo $modalId ?? 'genericModal'; ?>" class="modal">
    <div class="modal-overlay" onclick="closeModal('<?php echo $modalId ?? 'genericModal'; ?>')"></div>
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title"><?php echo $modalTitle ?? 'Modal Title'; ?></h3>
            <button class="modal-close" onclick="closeModal('<?php echo $modalId ?? 'genericModal'; ?>')">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <?php echo $modalContent ?? 'Modal content goes here'; ?>
        </div>
        <div class="modal-footer">
            <button class="btn-close" onclick="closeModal('<?php echo $modalId ?? 'genericModal'; ?>')">Cancel</button>
            <button class="btn-print" onclick="<?php echo $modalAction ?? 'submitModal()'; ?>">
                <?php echo $modalActionLabel ?? 'Submit'; ?>
            </button>
        </div>
    </div>
</div>

<script>
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Close modal on overlay click
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        const modal = e.target.closest('.modal');
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const activeModal = document.querySelector('.modal.active');
        if (activeModal) {
            activeModal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }
});
</script>