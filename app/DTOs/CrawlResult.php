<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\DTOs;

class CrawlResult
{
    public function __construct(
        public readonly string $url,
        public readonly string $pageTitle,
        public readonly array $interactiveElements,
        public readonly array $forms,
        public readonly array $navigation,
        public readonly array $meta,
    ) {}

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'page_title' => $this->pageTitle,
            'interactive_elements' => $this->interactiveElements,
            'forms' => $this->forms,
            'navigation' => $this->navigation,
            'meta' => $this->meta,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            url: $data['url'] ?? '',
            pageTitle: $data['page_title'] ?? '',
            interactiveElements: $data['interactive_elements'] ?? [],
            forms: $data['forms'] ?? [],
            navigation: $data['navigation'] ?? [],
            meta: $data['meta'] ?? [],
        );
    }

    public function interactiveElementCount(): int
    {
        return count($this->interactiveElements);
    }

    public function formCount(): int
    {
        return count($this->forms);
    }
}
