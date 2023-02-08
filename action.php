<?php

/**
 * DokuWiki Plugin imgpaste (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <gohr@cosmocode.de>
 */
class action_plugin_imgpaste extends DokuWiki_Action_Plugin
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
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjaxUpload');
    }

    /**
     * Creates a new file from the given data URL
     *
     * @param Doku_Event $event AJAX_CALL_UNKNOWN
     */
    public function handleAjaxUpload(Doku_Event $event)
    {
        if ($event->data != 'plugin_imgpaste') return;
        global $lang;

        // get data
        global $INPUT;
        $data = $INPUT->post->str('data');
        list($type, $data) = explode(';', $data);
        if (!$data) $this->fail(400, $this->getLang('e_nodata'));

        // process data encoding
        $type = strtolower(substr($type, 5)); // strip 'data:' prefix
        $data = substr($data, 7); // strip 'base64,' prefix
        $data = base64_decode($data);

        // check for supported mime type
        $mimetypes = array_flip(getMimeTypes());
        if (!isset($mimetypes[$type])) $this->fail(415, $lang['uploadwrong']);

        // prepare file names
        $tempname = $this->storetemp($data);
        $filename = $this->createFileName($INPUT->post->str('id'), $mimetypes[$type], $_SERVER['REMOTE_USER']);

        // check ACLs
        $auth = auth_quickaclcheck($filename);
        if ($auth < AUTH_UPLOAD) $this->fail(403, $lang['uploadfail']);

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
        if (is_array($result)) $this->fail(500, $result[0]);

        //Still here? We had a successful upload
        $this->clean();
        header('Content-Type: application/json');
        echo json_encode([
            'message' => $lang['uploadsucc'],
            'id' => $result,
            'mime' => $type,
            'ext' => $mimetypes[$type],
            'url' => ml($result),
        ]);

        $event->preventDefault();
        $event->stopPropagation();
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
        $filename .= '.' . $ext;
        return cleanID($filename);
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
        if (!$this->tempdir) $this->fail(500);
        $this->tempfile = $this->tempdir . '/' . md5($data);
        if (!io_saveFile($this->tempfile, $data)) $this->fail(500);
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

    /**
     * End the execution with a HTTP error code
     *
     * Calls clean
     *
     * @param int $status HTTP status code
     * @param string $text
     */
    protected function fail($status, $text = '')
    {
        $this->clean();
        http_status($status, $text);
        exit;
    }

}
