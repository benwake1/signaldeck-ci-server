## Crawl data from the user's website

The following data was extracted by crawling the user's site. Use these real selectors and page structure when generating tests.

**Page:** {!! $crawlData['page_title'] ?? 'Unknown' !!}
**URL:** {!! $crawlData['url'] ?? '' !!}

@if(!empty($crawlData['navigation']))
### Navigation links
@foreach(array_slice($crawlData['navigation'], 0, 30) as $link)
- {!! $link['text'] ?? '' !!} → {!! $link['href'] ?? '' !!}
@endforeach
@endif

@if(!empty($crawlData['forms']))
### Forms
@foreach(array_slice($crawlData['forms'], 0, 10) as $form)
**Form** {!! $form['id'] ? "(#{$form['id']})" : '' !!} — {{ $form['method'] }} {!! $form['action'] !!}
@foreach($form['fields'] ?? [] as $field)
  - {{ $field['tag'] }}[type={{ $field['type'] ?: 'text' }}] name="{!! $field['name'] !!}" {!! $field['label'] ? "label=\"{$field['label']}\"" : '' !!} {{ $field['required'] ? '(required)' : '' }}
@endforeach

@endforeach
@endif

@if(!empty($crawlData['interactive_elements']))
### Interactive elements ({{ count($crawlData['interactive_elements']) }} found)
@foreach(array_slice($crawlData['interactive_elements'], 0, 50) as $el)
- {{ $el['tag'] }}{!! $el['type'] ? "[type={$el['type']}]" : '' !!} {!! $el['selector'] ? "selector: {$el['selector']}" : '' !!} {!! $el['text'] ? "text: \"{$el['text']}\"" : '' !!}
@endforeach
@endif

@if(!empty($crawlData['meta']))
### Page metadata
@foreach($crawlData['meta'] as $key => $value)
@if(is_array($value))
- {!! $key !!}: {!! implode(', ', $value) !!}
@else
- {!! $key !!}: {!! $value !!}
@endif
@endforeach
@endif
