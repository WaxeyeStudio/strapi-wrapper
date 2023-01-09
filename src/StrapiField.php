<?php

namespace SilentWeb\StrapiWrapper;

use SilentWeb\StrapiWrapper\Exceptions\UnknownError;

class StrapiField
{
    private string $name;
    private StrapiCollection $collection;

    private array $filters = [];
    private array $linkedFilters = [];


    public function __construct(string $fieldName, StrapiCollection $collection)
    {
        $this->name = $fieldName;
        $this->collection = $collection;
    }

    /**
     * @deprecated deprecated since version 0.2.5
     */
    public function back(): StrapiCollection
    {
        return $this->collection;
    }

    public function or($by, string $how = StrapiFilter::Equals, array $fields = []): StrapiCollection
    {
        $this->multiFilter('$or.', $by, $how, [$this->name, ...$fields]);
        return $this->collection;
    }

    private function multiFilter($prefix, $by, $how, $fields): void
    {
        foreach ($fields as $index => $field) {
            $what = $prefix . $index . '.' . $field;
            $this->collection->field($what)->filter($by, $how);
            $this->linkedFilters[] = $what;
        }
    }

    /**
     * @param $by
     * @param string $how
     * @return StrapiCollection
     */
    public function filter($by, string $how = StrapiFilter::Equals): StrapiCollection
    {
        $this->filters[$how] = $by;
        return $this->collection;
    }

    public function and($by, string $how = StrapiFilter::Equals, array $fields = []): StrapiCollection
    {
        $this->multiFilter('$and.', $by, $how, [$this->name, ...$fields]);
        return $this->collection;
    }

    public function url(): string
    {
        $builder = [];
        foreach ($this->filters as $how => $by) {
            if ($this->collection->apiVersion() === 4) {
                // Check for deep filtering
                $deep = explode('.', $this->name);
                if (count($deep) > 1) {
                    $builder[] = "filters[" . implode('][', $deep) . "][$how]=" . urlencode($by);
                } else {
                    $builder[] = "filters[" . $this->name . "][$how]=" . urlencode($by);
                }
            } else {
                // TODO: Implement v3 filter
                throw new UnknownError('Unimplemented');
            }
        }
        return implode('&', $builder);
    }

    public function clearFilter($how): StrapiField
    {
        if ($this->filters[$how]) {
            unset($this->filters[$how]);
        }
        return $this;
    }

    public function clearFilters(): StrapiField
    {
        $this->filters = [];
        foreach ($this->linkedFilters as $filter) {
            $this->collection->field($filter)->clearFilters();
        }

        return $this;
    }
}
