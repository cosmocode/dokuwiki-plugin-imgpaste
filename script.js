(function () {

    /**
     * Handle pasting of files
     *
     * @param {ClipboardEvent} e
     */
    function handlePaste(e) {
        if (!document.getElementById('wiki__text')) return; // only when editing

        const items = (e.clipboardData || e.originalEvent.clipboardData).items;

        // When running prosemirror and pasting into its edit area, check for HTML paste first
        if (
            typeof window.proseMirrorIsActive !== 'undefined'
            && window.proseMirrorIsActive === true
            && document.activeElement.tagName === 'DIV'
            && document.activeElement.classList.contains('ProseMirror-focused')
        ) {
            for (let index in items) {
                const item = items[index];
                if (item.kind === 'string' && item.type === 'text/html') {
                    e.preventDefault();
                    e.stopPropagation();

                    item.getAsString(async html => {
                            html = await processHTML(html);
                            const pm = window.Prosemirror.view;
                            const parser = window.Prosemirror.classes.DOMParser.fromSchema(pm.state.schema);
                            const nodes = parser.parse(html);
                            pm.dispatch(pm.state.tr.replaceSelectionWith(nodes));
                        }
                    );

                    return; // we found an HTML item, no need to continue
                }
            }
        }

        // if we're still here, handle files
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
     * Creates and shows the progress dialog
     *
     * @returns {HTMLDivElement}
     */
    function progressDialog() {
        // create dialog
        const offset = document.querySelectorAll('.plugin_imagepaste').length * 3;
        const box = document.createElement('div');
        box.className = 'plugin_imagepaste';
        box.innerText = LANG.plugins.imgpaste.inprogress;
        box.style.position = 'fixed';
        box.style.top = offset + 'em';
        box.style.left = '1em';
        document.querySelector('.dokuwiki').append(box);
        return box;
    }

    /**
     * Processes the given HTML and downloads all images
     *
     * @param html
     * @returns {Promise<HTMLDivElement>}
     */
    async function processHTML(html) {
        const box = progressDialog();

        const div = document.createElement('div');
        div.innerHTML = html;
        const imgs = Array.from(div.querySelectorAll('img'));
        await Promise.all(imgs.map(async img => {
            if (img.src.startsWith(DOKU_BASE)) return; // skip local images
            if (!img.src.match(/^(https?:\/\/|data:)/i)) return; // we only handle http(s) and data URLs

            try {
                let result;
                if (img.src.startsWith('data:')) {
                    result = await uploadDataURL(img.src);
                } else {
                    result = await downloadData(img.src);
                }

                img.src = result.url;
                img.className = 'media';
                img.dataset.relid = getRelativeID(result.id);
            } catch (e) {
                console.error(e);
            }
        }));

        box.remove();
        return div;
    }

    /**
     * Tell the backend to download the given URL and return the new ID
     *
     * @param {string} imgUrl
     * @returns {Promise<object>} The JSON response
     */
    async function downloadData(imgUrl) {
        const formData = new FormData();
        formData.append('call', 'plugin_imgpaste');
        formData.append('url', imgUrl);
        formData.append('id', JSINFO.id);

        const response = await fetch(
            DOKU_BASE + 'lib/exe/ajax.php',
            {
                method: 'POST',
                body: formData
            }
        );

        if (!response.ok) {
            throw new Error(response.statusText);
        }

        return await response.json();
    }

    /**
     * Tell the backend to create a file from the given dataURL and return the new ID
     *
     * @param {string} dataURL
     * @returns {Promise<object>} The JSON response
     */
    async function uploadDataURL(dataURL) {
        const formData = new FormData();
        formData.append('call', 'plugin_imgpaste');
        formData.append('data', dataURL);
        formData.append('id', JSINFO.id);

        const response = await fetch(
            DOKU_BASE + 'lib/exe/ajax.php',
            {
                method: 'POST',
                body: formData
            }
        );

        if (!response.ok) {
            throw new Error(response.statusText);
        }

        return await response.json();
    }

    /**
     * Uploads the given dataURL to the server and displays a progress dialog, inserting the syntax on success
     *
     * @param {string} dataURL
     */
    async function uploadData(dataURL) {
        const box = progressDialog();

        try {
            const data = await uploadDataURL(dataURL);
            box.classList.remove('info');
            box.classList.add('success');
            box.innerText = data.message;
            setTimeout(() => {
                box.remove();
            }, 1000);
            insertSyntax(data.id);
        } catch (e) {
            box.classList.remove('info');
            box.classList.add('error');
            box.innerText = e.message;
            setTimeout(() => {
                box.remove();
            }, 1000);
        }
    }

    /**
     * Create a link ID for the given ID, preferrably relative to the current page
     *
     * @param {string} id
     * @returns {string}
     */
    function getRelativeID(id) {
        // TODO remove the "if" check after LinkWizard.createRelativeID() is available in stable (after Kaos)
        if (typeof LinkWizard !== 'undefined' && typeof LinkWizard.createRelativeID === 'function') {
            id = LinkWizard.createRelativeID(JSINFO.id, id);
        } else {
            id = ':' + id;
        }
        return id;
    }

    /**
     * Inserts the given ID into the current editor
     *
     * @todo add support for other editors like CKEditor
     * @param {string} id The newly uploaded file ID
     */
    function insertSyntax(id) {
        id = getRelativeID(id);

        if (typeof window.proseMirrorIsActive !== 'undefined' && window.proseMirrorIsActive === true) {
            const pm = window.Prosemirror.view;
            const imageNode = pm.state.schema.nodes.image.create({id: id});
            pm.dispatch(pm.state.tr.replaceSelectionWith(imageNode));
        } else {
            insertAtCarret('wiki__text', '{{' + id + '}}');
        }
    }

    // main
    window.addEventListener('paste', handlePaste, true);

})();
