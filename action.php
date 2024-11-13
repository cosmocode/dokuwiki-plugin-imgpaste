<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\plugin\imgpaste\Exception as PasteException;

/**
 * DokuWiki Plugin imgpaste (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <gohr@cosmocode.de>
 */
class action_plugin_imgpaste extends ActionPlugin
{

    protected $tempdir = '';
    protected $tempfile = '';

    /**
     * Clean up on destruction
     */
    public function __destruct()
    {
        $this->clean();
    }

    /** @inheritdoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjaxUpload');
    }


    /**
     * Creates a new file from the given data URL
     *
     * @param Event $event AJAX_CALL_UNKNOWN
     */
    public function handleAjaxUpload(Event $event)
    {
        if ($event->data != 'plugin_imgpaste') return;
        $event->preventDefault();
        $event->stopPropagation();

        global $INPUT;
        try {
            if ($INPUT->has('url')) {
                [$data, $type] = $this->externalUrlToData($INPUT->post->str('url'));
            } else {
                [$data, $type] = $this->dataUrlToData($INPUT->post->str('data'));
            }
            $result = $this->storeImage($data, $type);
        } catch (PasteException $e) {
            $this->clean();
            http_status($e->getCode(), $e->getMessage());
            exit;
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * Get the binary data and mime type from a data URL
     *
     * @param string $dataUrl
     * @return array [data, type]
     * @throws PasteException
     */
    protected function dataUrlToData($dataUrl)
    {
        list($type, $data) = explode(';', $dataUrl);
        if (!$data) throw new PasteException($this->getLang('e_nodata'), 400);

        // process data encoding
        $type = strtolower(substr($type, 5)); // strip 'data:' prefix
        $data = substr($data, 7); // strip 'base64,' prefix
        $data = base64_decode($data);
        return [$data, $type];
    }

    /**
     * Download the file from an external URL
     *
     * @param string $externalUrl
     * @return array [data, type]
     * @throws PasteException
     */
    protected function externalUrlToData($externalUrl)
    {
        global $lang;

        // download the file
        $http = new \dokuwiki\HTTP\DokuHTTPClient();
        $data = $http->get($externalUrl);
        if (!$data) throw new PasteException($lang['uploadfail'], 500);
        [$type] = explode(';', $http->resp_headers['content-type']);
        return [$data, $type];
    }

    /**
     * @throws PasteException
     */
    protected function storeImage($data, $type)
    {
        global $lang;
        global $INPUT;

        // check for supported mime type
        $mimetypes = array_flip(getMimeTypes());
        if (!isset($mimetypes[$type])) throw new PasteException($lang['uploadwrong'], 415);

        // prepare file names
        $tempname = $this->storetemp($data);
        $filename = $this->createFileName($INPUT->post->str('id'), $mimetypes[$type], $_SERVER['REMOTE_USER']);

        // check ACLs
        $auth = auth_quickaclcheck($filename);
        if ($auth < AUTH_UPLOAD) throw new PasteException($lang['uploadfail'], 403);

        // do the actual saving
        $result = media_save(
            [
                'name' => $tempname,
                'mime' => $type,
                'ext' => $mimetypes[$type],
            ],
            $filename,
            false,
            $auth,
            'copy'
        );
        if (is_array($result)) throw  new PasteException($result[0], 500);

        //Still here? We had a successful upload
        $this->clean();
        return [
            'message' => $lang['uploadsucc'],
            'id' => $result,
            'mime' => $type,
            'ext' => $mimetypes[$type],
            'url' => ml($result),
        ];
    }

    /**
     * Create the filename for the new file
     *
     * @param string $pageid the original page the paste event happend on
     * @param string $ext the extension of the file
     * @param string $user the currently logged in user
     * @return string
     */
    protected function createFileName($pageid, $ext, $user)
    {
        $unique = '';
        $filename = $this->getConf('filename');
        $filename = str_replace(
            [
                '@NS@',
                '@ID@',
                '@USER@',
                '@PAGE@',
            ],
            [
                getNS($pageid),
                $pageid,
                $user,
                noNS($pageid),
            ],
            $filename
        );
        $filename = strftime($filename);
        $filename = cleanID($filename);
        while (media_exists($filename . $unique . '.' . $ext)) {
            $unique = (int)$unique + 1;
        }
        return $filename . $unique . '.' . $ext;
    }

    /**
     * Create a temporary file from the given data
     *
     * exits if an error occurs
     *
     * @param $data
     * @return string
     */
    protected function storetemp($data)
    {
        // store in temporary file
        $this->tempdir = io_mktmpdir();
        if (!$this->tempdir) throw new PasteException('', 500);
        $this->tempfile = $this->tempdir . '/' . md5($data);
        if (!io_saveFile($this->tempfile, $data)) throw new PasteException('', 500);
        return $this->tempfile;
    }

    /**
     * remove temporary file and directory
     */
    protected function clean()
    {
        if ($this->tempfile && file_exists($this->tempfile)) @unlink($this->tempfile);
        if ($this->tempdir && is_dir($this->tempdir)) @rmdir($this->tempdir);
        $this->tempfile = '';
        $this->tempdir = '';
    }

}
