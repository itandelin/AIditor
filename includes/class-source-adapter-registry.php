<?php
declare(strict_types=1);

class AIditor_Source_Adapter_Registry
{
    /**
     * @var array<int, object>
     */
    protected array $adapters;

    public function __construct(?array $adapters = null)
    {
        $this->adapters = null !== $adapters ? $adapters : array();
    }

    public function resolve(string $source_url)
    {
        foreach ($this->adapters as $adapter) {
            if (method_exists($adapter, 'supports') && $adapter->supports($source_url)) {
                return $adapter;
            }
        }

        throw new InvalidArgumentException('固定来源适配器已移除，请使用通用采集模板。');
    }
}
