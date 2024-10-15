<?php

namespace Lunar\Search\Engines;

use Lunar\Search\Data\SearchFacet;
use Lunar\Search\Data\SearchHit;
use Lunar\Search\Data\SearchResults;
use Typesense\Documents;

class TypesenseEngine extends AbstractEngine
{
    public function get(): SearchResults
    {
        $paginator = $this->getRawResults(function (Documents $documents, string $query, array $options) {

            $filters = collect();

            foreach ($this->filters as $key => $value) {
                $filters->push($key.':'.collect($value)->join(','));
            }

            foreach ($this->facets as $field => $values) {
                $values = collect($values)->map(
                    fn ($value) => '`'.$value.'`'
                );

                if ($values->count() > 1) {
                    $filters->push($field.':['.collect($values)->join(',').']');

                    continue;
                }

                $filters->push($field.':'.collect($values)->join(','));
            }

            $options['q'] = $query;
            $facets = $this->getFacetConfig();

            $options['facet_by'] = implode(',', array_keys($facets));
            $options['max_facet_values'] = 50;
            $options['per_page'] = $this->perPage;
            $options['sort_by'] = $this->sort;

            if ($filters->count()) {
                $options['filter_by'] = $filters->join(' && ');
            }

            return $documents->search($options);
        });

        $results = $paginator->items();

        $documents = collect($results['hits'])->map(fn ($hit) => SearchHit::from([
            'highlights' => collect($hit['highlights'])->map(
                fn ($highlight) => SearchHit\Highlight::from([
                    'field' => $highlight['field'],
                    'matches' => $highlight['matched_tokens'],
                    'snippet' => $highlight['snippet'],
                ])
            ),
            'document' => $hit['document'],
        ]));

        $facets = collect($results['facet_counts'])->map(
            fn ($facet) => SearchFacet::from([
                'label' => $this->getFacetConfig($facet['field_name'])['label'] ?? '',
                'field' => $facet['field_name'],
                'values' => collect($facet['counts'])->map(
                    fn ($value) => SearchFacet\FacetValue::from([
                        'value' => $value['value'],
                        'count' => $value['count'],
                    ])
                ),
            ])
        );

        foreach ($facets as $facet) {
            $facetConfig = $this->getFacetConfig($facet->field);

            foreach ($facet->values as $faceValue) {
                if (empty($facetConfig[$faceValue->value])) {
                    continue;
                }
                $faceValue->additional($facetConfig[$faceValue->value]);
            }
        }

        $data = [
            'query' => $results['request_params']['q'],
            'total_pages' => $paginator->lastPage(),
            'page' => $paginator->currentPage(),
            'count' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'hits' => $documents,
            'facets' => $facets,
            'links' => $paginator->appends([
                'perPage' => $this->perPage,
                'facets' => http_build_query($this->facets),
            ])->linkCollection()->toArray(),
        ];

        return SearchResults::from($data);
    }
}
