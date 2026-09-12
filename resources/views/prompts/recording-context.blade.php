## Recorded user flow

The following is a real, recorded sequence of actions a person performed on the live site — not a guess. Replay these exact steps and selectors in order rather than inventing alternative selectors or a different flow. Where a step is marked "sensitive", its real value was never captured; reference it via an environment variable instead of a literal (`process.env.VAR_NAME` for Playwright, `Cypress.env('VAR_NAME')` for Cypress) and pick a clear, uppercase snake_case name that reflects what the field is (e.g. `SITE_PASSWORD`, `CARD_NUMBER`).

For CLICK steps specifically:
- When a step includes a `link target`, its sole purpose was navigation. Prefer `page.goto(<link target>)` over clicking through the UI for that step — many real sites intercept a plain click on a top-level nav link with JS (mega-menus, dropdown toggles) so the click never actually navigates even though the link has a real destination. A direct navigation is more reliable and just as faithful to what the user was trying to do.
- When a step's selector is a **bare tag name** (e.g. `img`, `path`, `svg` — no id/name/aria-label/text available to disambiguate it), a `match position` is given. Use it precisely, e.g. `page.locator('img').nth(<index>)`, rather than guessing `.first()` — the page likely has other same-tag elements (hidden menu icons, decorative SVGs) earlier in the DOM that `.first()` would grab instead. If the step also says the element was visible, additionally filter for that (e.g. Playwright's `.filter({ visible: true })`) so the check stays robust if the DOM order shifts slightly.

@foreach($recordingData as $i => $action)
{{ $i + 1 }}. **{{ strtoupper($action['type'] ?? 'action') }}** `{!! $action['selector'] ?? '' !!}` on {!! $action['url'] ?? '' !!}
@if(!empty($action['sensitive']))
   — value redacted (sensitive field), use an environment variable
@elseif(array_key_exists('value', $action) && $action['value'] !== null && $action['value'] !== '')
   — value: "{!! $action['value'] !!}"
@endif
@if(!empty($action['href']))
   — link target: {!! $action['href'] !!}
@endif
@if(array_key_exists('matchIndex', $action) && $action['matchIndex'] !== null && array_key_exists('matchCount', $action) && $action['matchCount'] !== null)
   — match position: {{ $action['matchIndex'] }} of {{ $action['matchCount'] }} `{!! $action['selector'] ?? '' !!}` elements on the page (0-indexed)
@endif
@if(array_key_exists('visible', $action) && $action['visible'] === false)
   — note: this element was not visible in the normal page flow when clicked (e.g. revealed by hover/JS)
@endif
@endforeach
