<?php
namespace ryunosuke\DbMigration;

class Exportion implements \JsonSerializable
{
    private $dirname;

    private $localname;

    private $data;

    private $provider;

    public function __construct($dirname, $localname, $data, $provider = null)
    {
        $this->dirname = $dirname;
        $this->localname = $localname;
        $this->data = $data;
        $this->provider = $provider;
    }

    public function setProvider($provider)
    {
        $this->provider = $provider;
        return $this;
    }

    public function export()
    {
        $content = call_user_func($this->provider, $this->data);
        Utility::file_put_contents("{$this->dirname}/{$this->localname}", $content);

        return $this->localname;
    }

    public function jsonSerialize()
    {
        return '!include: ' . $this->export();
    }
}
