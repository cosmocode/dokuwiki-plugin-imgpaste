(function () {

    /**
     * Handle pasting of files
     *
     * @param {ClipboardEvent} e
     */
    function handlePaste(e) {
        if (!document.getElementById('wiki__text')) return; // only when editing

        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
        for (let index in items) {
            const item = items[index];

            if (item.kind === 'file') {
                const reader = new FileReader();
                reader.onload = event => {
                    uploadData(event.target.result);
                };
                reader.readAsDataURL(item.getAsFile());

                // we had at least one file, prevent default
                e.preventDefault();
                e.stopPropagation();
            }
        }
    }

    /**
     * Uploads the given dataURL to the server and displays a progress dialog
     *
     * @param {string} dataURL
     */
    function uploadData(dataURL) {
        // create dialog
        const offset = document.querySelectorAll('.plugin_imagepaste').length * 3;
        const box = document.createElement('div');
        box.className = 'plugin_imagepaste';
        box.innerText = LANG.plugins.imgpaste.inprogress;
        box.style.position = 'fixed';
        box.style.top = offset + 'em';
        box.style.left = '1em';
        document.querySelector('.dokuwiki').append(box);

        // upload via AJAX
        jQuery.ajax({
            url: DOKU_BASE + 'lib/exe/ajax.php',
            type: 'POST',
            data: {
                call: 'plugin_imgpaste',
                data: dataURL,
                id: JSINFO.id
            },

            // insert syntax and close dialog
            success: function (data) {
                box.classList.remove('info');
                box.classList.add('success');
                box.innerText = data.message;
                setTimeout(() => {
                    box.remove();
                }, 1000);
                insertSyntax(data.id);
            },

            // display error and close dialog
            error: function (xhr, status, error) {
                box.classList.remove('info');
                box.classList.add('error');
                box.innerText = error;
                setTimeout(() => {
                    box.remove();
                }, 1000);
            }
        });
    }

    /**
     * Inserts the given ID into the current editor
     *
     * @todo add suppprt for other editors like Prosemirror or CKEditor
     * @param {string} id The newly uploaded file ID
     */
    function insertSyntax(id) {
        insertAtCarret('wiki__text', '{{:' + id + '}}');
    }

    // main
    window.addEventListener('paste', handlePaste);

})();
