<?php

namespace SilentWeb\StrapiWrapper;

class StrapiField
{
    private string $name;
    private StrapiCollection $collection;

    private array $filters = [];


    public function __construct(string $fieldName, StrapiCollection $collection)
    {
        $this->name = $fieldName;
        $this->collection = $collection;
    }

    public function back(): StrapiCollection
    {
        return $this->collection;
    }

    /**
     * @param $how
     * @param $by
     * @return StrapiField
     */
    public function filter($how, $by): StrapiField
    {
        $this->filters[$how] = $by;
        return $this;
    }

    public function url(): string
    {
        $builder = [];
        foreach ($this->filters as $how => $by) {
            if ($this->collection->apiVersion() === 4) {
                $builder[] = "filters[" . $this->name . "][$how]=" . urlencode($by);
            }
        }
        return implode('&', $builder);
    }

    public function clearFilter($how): StrapiField
    {
        if ($this->filters[$how]) unset($this->filters[$how]);
        return $this;
    }

    public function clearFilters(): StrapiField
    {
        $this->filters = [];
        return $this;
    }
}
