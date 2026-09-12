<x-filament-panels::page>

    <style>
        .ai-chat-container{display:flex;gap:1rem;min-height:75vh;position:relative}
        .ai-main{flex:1;display:flex;flex-direction:column;min-width:0}
        .ai-code-panel{width:45%;max-width:600px;flex-shrink:0;transition:width .2s ease}
        .chat-messages{flex:1;overflow-y:auto;max-height:60vh;padding:1rem;scroll-behavior:smooth}
        .chat-bubble{max-width:80%;padding:.75rem 1rem;border-radius:.75rem;margin-bottom:.75rem;word-wrap:break-word;font-size:.875rem;line-height:1.5}
        .chat-bubble-user{margin-left:auto;background:#3b82f6;color:#fff;border-bottom-right-radius:.25rem;white-space:pre-wrap}
        .chat-bubble-assistant{margin-right:auto;background:#f3f4f6;color:#1f2937;border-bottom-left-radius:.25rem}
        .dark .chat-bubble-assistant{background:#374151;color:#e5e7eb}
        .chat-bubble pre{background:rgba(0,0,0,.06);border-radius:.5rem;padding:.75rem;overflow-x:auto;margin:.5rem 0;font-size:.8rem;line-height:1.5}
        .dark .chat-bubble pre{background:rgba(0,0,0,.3)}
        .chat-bubble pre code{background:none;padding:0;font-size:inherit;color:inherit}
        .chat-bubble code{background:rgba(0,0,0,.06);padding:.125rem .375rem;border-radius:.25rem;font-size:.8rem;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
        .dark .chat-bubble code{background:rgba(255,255,255,.1)}
        .chat-bubble-assistant h1,.chat-bubble-assistant h2,.chat-bubble-assistant h3{font-weight:600;margin:.75rem 0 .375rem;line-height:1.3}
        .chat-bubble-assistant h1{font-size:1.1rem}
        .chat-bubble-assistant h2{font-size:1rem}
        .chat-bubble-assistant h3{font-size:.9rem}
        .chat-bubble-assistant ul,.chat-bubble-assistant ol{margin:.375rem 0;padding-left:1.5rem}
        .chat-bubble-assistant ul{list-style:disc}
        .chat-bubble-assistant ol{list-style:decimal}
        .chat-bubble-assistant li{margin-bottom:.25rem}
        .chat-bubble-assistant p{margin:.375rem 0}
        .chat-bubble-assistant p:first-child{margin-top:0}
        .chat-bubble-assistant p:last-child{margin-bottom:0}
        .chat-bubble-assistant strong{font-weight:600}
        .chat-bubble-assistant em{font-style:italic}
        .chat-bubble-assistant a{color:#3b82f6;text-decoration:underline}
        .chat-bubble-assistant blockquote{border-left:3px solid #d1d5db;padding-left:.75rem;margin:.5rem 0;color:#6b7280}
        .dark .chat-bubble-assistant blockquote{border-left-color:#4b5563;color:#9ca3af}
        .chat-bubble-assistant hr{border:none;border-top:1px solid #e5e7eb;margin:.75rem 0}
        .dark .chat-bubble-assistant hr{border-top-color:#374151}
        .chat-bubble-assistant table{border-collapse:collapse;margin:.5rem 0;font-size:.8rem;width:100%}
        .chat-bubble-assistant th,.chat-bubble-assistant td{border:1px solid #d1d5db;padding:.375rem .5rem;text-align:left}
        .dark .chat-bubble-assistant th,.dark .chat-bubble-assistant td{border-color:#4b5563}
        .chat-bubble-assistant th{background:rgba(0,0,0,.04);font-weight:600}
        .dark .chat-bubble-assistant th{background:rgba(255,255,255,.05)}
        .chat-timestamp{font-size:.65rem;margin-top:.375rem;opacity:.55}
        .chat-bubble-user .chat-timestamp{text-align:right}
        .chat-bubble-assistant .chat-timestamp{text-align:left}
        .chat-input-area{border-top:1px solid #e5e7eb;padding:.75rem}
        .dark .chat-input-area{border-color:#374151}
        .chat-textarea{resize:none;min-height:2.5rem;max-height:10rem;field-sizing:content}
        .file-tab{cursor:pointer;padding:.375rem .75rem;border-radius:.375rem .375rem 0 0;font-size:.75rem;font-family:monospace;transition:background .15s}
        .file-tab.active{background:#3b82f6;color:#fff}
        .file-tab:not(.active){background:#e5e7eb;color:#6b7280}
        .dark .file-tab:not(.active){background:#374151;color:#9ca3af}
        .code-display{background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:.8rem;padding:1rem;overflow:auto;max-height:55vh;border-radius:0 0 .5rem .5rem;white-space:pre;line-height:1.6}
        .conversation-item{cursor:pointer;padding:.5rem .75rem;border-radius:.375rem;font-size:.8125rem;transition:background .15s;white-space:nowrap}
        .conversation-item:hover{background:#e5e7eb}
        .dark .conversation-item:hover{background:#374151}
        .conversation-item.active{background:#dbeafe;color:#1d4ed8}
        .dark .conversation-item.active{background:#1e3a8a;color:#93c5fd}
        .framework-toggle{display:inline-flex;border-radius:.375rem;overflow:hidden;border:1px solid #d1d5db}
        .dark .framework-toggle{border-color:#4b5563}
        .framework-toggle button{padding:.25rem .75rem;font-size:.75rem;font-weight:500;transition:all .15s;border:none;cursor:pointer}
        .framework-toggle button.active{background:#3b82f6;color:#fff}
        .framework-toggle button:not(.active){background:transparent;color:#6b7280}
        .dark .framework-toggle button:not(.active){color:#9ca3af}
        .crawl-badge{display:inline-flex;align-items:center;gap:.25rem;padding:.125rem .5rem;border-radius:9999px;font-size:.6875rem;font-weight:500;background:#dcfce7;color:#166534}
        .dark .crawl-badge{background:#052e16;color:#86efac}
        .loading-dot{animation:loadingPulse 1.4s infinite both}
        .loading-dot:nth-child(2){animation-delay:.2s}
        .loading-dot:nth-child(3){animation-delay:.4s}
        @keyframes loadingPulse{0%,80%,100%{opacity:.3}40%{opacity:1}}

        /* Sidebar drawer overlay */
        .sidebar-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:40;transition:opacity .2s}
        .sidebar-drawer{position:fixed;top:0;left:0;bottom:0;width:320px;z-index:50;transform:translateX(-100%);transition:transform .25s ease}
        .sidebar-drawer.open{transform:translateX(0)}
        .dark .sidebar-backdrop{background:rgba(0,0,0,.5)}

        @media(max-width:1024px){
            .ai-code-panel{display:none}
        }
    </style>

    <div class="ai-chat-container" x-data="{ showCodePanel: true, showSidebar: false }">

        {{-- ═══ Sidebar Drawer (slide-over) ═══ --}}
        <div
            x-show="showSidebar"
            x-cloak
            class="sidebar-backdrop"
            @click="showSidebar = false"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
        ></div>
        <div
            :class="{ 'open': showSidebar }"
            class="sidebar-drawer"
            @keydown.escape.window="showSidebar = false"
        >
            <div class="bg-white dark:bg-gray-900 h-full shadow-2xl border-r border-gray-200 dark:border-gray-700 p-5 flex flex-col gap-4 overflow-y-auto p-6">

                {{-- Drawer header --}}
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Conversations</h3>
                    <button
                        @click="showSidebar = false"
                        class="p-1 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
                    >
                        <x-heroicon-s-x-mark class="w-5 h-5" />
                    </button>
                </div>

                {{-- Project selector --}}
                <div>
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-2 block">Project</label>
                    <select
                        wire:model.live="projectId"
                        wire:change="selectProject($event.target.value)"
                        class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                    >
                        <option value="">Select a project...</option>
                        @foreach($this->projects as $project)
                            <option value="{{ $project['id'] }}">{{ $project['name'] }}</option>
                        @endforeach
                    </select>
                </div>

                @if($projectId)
                    {{-- New conversation button --}}
                    <button
                        wire:click="newConversation"
                        @click="showSidebar = false"
                        class="w-full flex items-center justify-center gap-2 px-3 py-2.5 text-sm font-medium text-white bg-primary-600 rounded-lg hover:bg-primary-500 transition"
                    >
                        <x-heroicon-s-plus class="w-4 h-4" />
                        New Chat
                    </button>

                    {{-- Conversation history --}}
                    <div class="flex-1 overflow-y-auto -mx-1 px-1">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Recent</p>
                            @if(count($this->conversations) > 0)
                                <button
                                    wire:click="clearAllConversations"
                                    wire:confirm="Clear all conversations for this project? This cannot be undone."
                                    class="text-[.65rem] text-gray-400 hover:text-danger-500 dark:text-gray-500 dark:hover:text-danger-400 transition"
                                    title="Clear all conversations"
                                >
                                    Clear All
                                </button>
                            @endif
                        </div>
                        @forelse($this->conversations as $conv)
                            <div
                                @class([
                                    'conversation-item mb-1 group flex items-center gap-2',
                                    'active' => $conversationUlid === $conv['ulid'],
                                ])
                                title="{{ $conv['title'] }} — {{ $conv['updated_at'] }}"
                            >
                                {{-- Status dot --}}
                                <span @class([
                                    'shrink-0 w-1.5 h-1.5 rounded-full',
                                    'bg-emerald-500' => $conv['status'] === 'active',
                                    'bg-blue-500' => $conv['status'] === 'completed',
                                    'bg-red-500' => $conv['status'] === 'failed',
                                ])></span>
                                @if($conv['is_repair'] ?? false)
                                    <x-heroicon-m-wrench-screwdriver
                                        class="shrink-0 w-3 h-3 text-warning-500"
                                        title="Automated repair proposal — not merged, review before accepting"
                                    />
                                @endif
                                <span
                                    wire:click="loadConversation('{{ $conv['ulid'] }}')"
                                    @click="showSidebar = false"
                                    class="flex-1 truncate cursor-pointer"
                                >
                                    {{ $conv['title'] }}
                                </span>
                                <button
                                    wire:click.stop="deleteConversation('{{ $conv['ulid'] }}')"
                                    wire:confirm="Delete this conversation?"
                                    class="shrink-0 p-0.5 rounded group-hover:opacity-100 hover:bg-gray-100 text-gray-400 hover:text-danger-500 transition"
                                    title="Delete conversation"
                                >
                                    <x-heroicon-m-x-mark class="w-3.5 h-3.5" />
                                </button>
                            </div>
                        @empty
                            <p class="text-xs text-gray-400 dark:text-gray-500 italic">No conversations yet</p>
                        @endforelse
                    </div>
                @else
                    <div class="flex-1 flex items-center justify-center">
                        <p class="text-xs text-gray-400 dark:text-gray-500 italic text-center">Select a project to start</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- ═══ Main Chat Panel ═══ --}}
        <div class="ai-main">
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 flex flex-col h-full">

                {{-- Header bar (always visible) --}}
                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center gap-3">
                        {{-- Sidebar toggle --}}
                        <button
                            @click="showSidebar = true"
                            class="p-1.5 -ml-1 rounded-lg text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-800 transition"
                            title="Conversations & projects"
                        >
                            <x-heroicon-o-bars-3 class="w-5 h-5" />
                        </button>

                        @if($projectId)
                            {{-- Project name --}}
                            <span class="text-xs font-medium text-gray-400 dark:text-gray-500">
                                {{ \App\Models\Project::find($projectId)?->name }}
                            </span>
                            <span class="text-gray-300 dark:text-gray-600">/</span>
                        @endif

                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                            @if($conversationUlid)
                                {{ $this->getConversation()?->title ?? 'Chat' }}
                            @elseif($projectId)
                                New Conversation
                            @else
                                AI Test Builder
                            @endif
                        </h3>

                        @if($this->getConversation()?->crawl_data)
                            <span class="crawl-badge">
                                <x-heroicon-s-globe-alt class="w-3 h-3" />
                                Crawled
                            </span>
                        @elseif($this->getProjectCrawlUrl())
                            <span class="crawl-badge" title="Using project sitemap: {{ $this->getProjectCrawlUrl() }}">
                                <x-heroicon-s-globe-alt class="w-3 h-3" />
                                Sitemap
                            </span>
                        @endif
                    </div>

                    @if($projectId)
                        <div class="flex items-center gap-3">
                            {{-- Record a Flow --}}
                            @if(!$recordingToken)
                                <button
                                    wire:click="startRecording"
                                    class="flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-950 rounded-lg hover:bg-rose-100 dark:hover:bg-rose-900 transition"
                                    title="Record yourself performing a real multi-step flow on the live site"
                                >
                                    <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                                    Record a Flow
                                </button>
                            @endif

                            {{-- Code panel toggle (when files exist but panel is hidden) --}}
                            @if(!empty($generatedFiles))
                                <button
                                    x-show="!showCodePanel"
                                    x-cloak
                                    @click="showCodePanel = true"
                                    class="flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-950 rounded-lg hover:bg-primary-100 dark:hover:bg-primary-900 transition"
                                >
                                    <x-heroicon-s-code-bracket class="w-3.5 h-3.5" />
                                    Show Code
                                </button>
                            @endif

                            {{-- Framework toggle --}}
                            <div class="framework-toggle">
                                <button
                                    wire:click="switchFramework('cypress')"
                                    @class(['active' => $framework === 'cypress'])
                                >Cypress</button>
                                <button
                                    wire:click="switchFramework('playwright')"
                                    @class(['active' => $framework === 'playwright'])
                                >Playwright</button>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Recording panel --}}
                @if($projectId && $recordingToken)
                    <div
                        @if($recordingStatus === 'recording') wire:poll.3s="refreshRecordingStatus" @endif
                        class="mx-4 mt-3 px-3 py-3 rounded-lg text-xs bg-rose-50 dark:bg-rose-500/10 text-rose-800 dark:text-rose-300 space-y-2"
                    >
                        @if($recordingStatus === 'recording')
                            <div class="flex items-center justify-between gap-2">
                                <div class="font-medium">🔴 Recording — {{ $recordingStepCount }} step(s) captured</div>
                            </div>
                            <p class="opacity-90">
                                The Flow Recorder extension is now armed. Switch to the live site and start clicking through the flow —
                                it captures automatically on every page, including after navigating to a new domain (e.g. a payment gateway).
                                No need to click anything per page. Open the extension icon and choose
                                <strong>Stop &amp; save recording</strong> when the flow is done.
                            </p>
                            <p class="text-[.65rem] opacity-70">
                                Don't have the extension installed yet? See
                                <code class="px-1 py-0.5 rounded bg-rose-100 dark:bg-rose-900/50">browser-extension/README.md</code> for install steps.
                                Password fields are never captured — their values stay in your browser.
                            </p>
                        @elseif($recordingStatus === 'completed')
                            <div class="flex items-center justify-between gap-2">
                                <div class="font-medium">✅ Recording saved — {{ $recordingStepCount }} step(s)</div>
                                <button
                                    wire:click="generateFromRecording"
                                    class="px-3 py-1.5 text-xs font-medium text-white bg-primary-600 rounded-lg hover:bg-primary-500 transition disabled:opacity-50"
                                >
                                    <span wire:loading.remove wire:target="generateFromRecording">Generate test from recording</span>
                                    <span wire:loading wire:target="generateFromRecording">Generating…</span>
                                </button>
                            </div>
                        @else
                            <div class="font-medium">⚠️ Recording session expired — start a new recording.</div>
                        @endif
                    </div>
                @endif

                {{-- Env vars needed callout --}}
                @if(!empty($envVarsNeeded))
                    <div class="mx-4 mt-3 px-3 py-2 rounded-lg text-xs bg-amber-50 dark:bg-amber-500/10 text-amber-800 dark:text-amber-300">
                        This test references {{ count($envVarsNeeded) === 1 ? 'an environment variable' : 'environment variables' }}
                        that {{ count($envVarsNeeded) === 1 ? "isn't" : "aren't" }} set yet:
                        @foreach($envVarsNeeded as $name)
                            <code class="px-1 py-0.5 rounded bg-amber-100 dark:bg-amber-900/50">{{ $name }}</code>@if(!$loop->last), @endif
                        @endforeach
                        — add {{ count($envVarsNeeded) === 1 ? 'it' : 'them' }} under the project's Environment Variables before running.
                    </div>
                @endif

                @if(!$projectId)
                    {{-- No project selected — full-panel prompt --}}
                    <div class="flex-1 flex flex-col items-center justify-center text-center px-8">
                        <div class="w-16 h-16 rounded-2xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center mb-4">
                            <x-heroicon-o-folder-open class="w-8 h-8 text-gray-400 dark:text-gray-500" />
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Select a Project</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 max-w-sm mb-4">
                            Choose a project to start building AI-generated tests.
                        </p>
                        <button
                            @click="showSidebar = true"
                            class="px-4 py-2 text-sm font-medium text-white bg-primary-600 rounded-lg hover:bg-primary-500 transition"
                        >
                            Select Project
                        </button>
                    </div>
                @else
                    {{-- Chat messages area --}}
                    <div class="chat-messages" id="chat-messages">
                        @if(empty($chatMessages) && !$conversationUlid)
                            {{-- Empty state --}}
                            <div class="flex flex-col items-center justify-center h-full text-center px-8">
                                <div class="w-16 h-16 rounded-2xl bg-primary-50 dark:bg-primary-950 flex items-center justify-center mb-4">
                                    <x-heroicon-o-sparkles class="w-8 h-8 text-primary-500" />
                                </div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">AI Test Builder</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md mb-6">
                                    Describe the tests you need in plain English.
                                    @if($this->getProjectCrawlUrl())
                                        This project has a saved sitemap from <strong>{{ $this->getProjectCrawlUrl() }}</strong> that will be used automatically.
                                    @else
                                        Optionally crawl your site first for real selectors and page structure.
                                    @endif
                                </p>

                                {{-- Crawl input --}}
                                <div class="w-full max-w-md">
                                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1 block text-left">
                                        {{ $this->getProjectCrawlUrl() ? 'Re-crawl to update sitemap' : 'Crawl a page first (optional)' }}
                                    </label>
                                    <div class="flex gap-2">
                                        <input
                                            type="url"
                                            wire:model="crawlUrl"
                                            placeholder="https://example.com"
                                            class="flex-1 text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                                        />
                                        <button
                                            wire:click="crawlSite"
                                            class="px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-500 transition disabled:opacity-50"
                                        >
                                            <span wire:loading.remove wire:target="crawlSite">Crawl</span>
                                            <span wire:loading wire:target="crawlSite" class="flex items-center gap-1">
                                                <x-heroicon-s-arrow-path class="w-4 h-4 animate-spin" />
                                                Crawling...
                                            </span>
                                        </button>
                                    </div>
                                </div>

                                {{-- Quick-start prompt chips --}}
                                <div class="flex flex-wrap justify-center gap-2 mt-6">
                                    @foreach([
                                        'Generate login page tests',
                                        'Test the checkout flow',
                                        'Add accessibility tests',
                                        'Test form validation',
                                    ] as $chip)
                                        <button
                                            type="button"
                                            @click="$wire.set('userMessage', '{{ $chip }}'); $nextTick(() => $refs.chatInput?.focus())"
                                            class="px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 rounded-full hover:bg-primary-50 hover:text-primary-700 dark:hover:bg-primary-950 dark:hover:text-primary-400 border border-gray-200 dark:border-gray-700 transition"
                                        >
                                            {{ $chip }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            {{-- Message history --}}
                            @foreach($chatMessages as $msgIndex => $msg)
                                <div wire:key="msg-{{ $msgIndex }}-{{ md5($msg['content'] ?? '') }}" @class([
                                    'chat-bubble',
                                    'chat-bubble-user' => ($msg['role'] ?? '') === 'user',
                                    'chat-bubble-assistant' => ($msg['role'] ?? '') === 'assistant',
                                ])>
                                    @if(($msg['role'] ?? '') === 'assistant')
                                        <div
                                            x-data="{ rendered: '' }"
                                            x-init="rendered = window.renderMarkdown(@js($msg['content'] ?? ''))"
                                            x-html="rendered"
                                        ></div>
                                    @else
                                        {!! nl2br(e($msg['content'] ?? '')) !!}
                                    @endif
                                    @if(!empty($msg['timestamp']))
                                        <div class="chat-timestamp">
                                            {{ \Carbon\Carbon::parse($msg['timestamp'])->format('M j, g:ia') }}
                                        </div>
                                    @endif
                                </div>
                            @endforeach

                            {{-- Animated loading bubble (client-side, shows instantly) --}}
                            <div
                                wire:loading
                                wire:target="sendMessage"
                                class="chat-bubble chat-bubble-assistant"
                                x-data="aiLoadingMessages()"
                                x-init="start()"
                                x-on:livewire-upload-progress.window="stop()"
                            >
                                <div class="flex items-center gap-2">
                                    <span class="flex gap-0.5">
                                        <span class="loading-dot w-1.5 h-1.5 rounded-full bg-primary-500 inline-block"></span>
                                        <span class="loading-dot w-1.5 h-1.5 rounded-full bg-primary-500 inline-block"></span>
                                        <span class="loading-dot w-1.5 h-1.5 rounded-full bg-primary-500 inline-block"></span>
                                    </span>
                                    <span class="text-gray-500 dark:text-gray-400" x-text="message"></span>
                                </div>
                            </div>

                            {{-- Crawl loading indicator --}}
                            <div
                                wire:loading
                                wire:target="crawlSite"
                                class="chat-bubble chat-bubble-assistant"
                            >
                                <div class="flex items-center gap-2">
                                    <x-heroicon-s-arrow-path class="w-4 h-4 animate-spin text-emerald-500" />
                                    <span class="text-gray-500 dark:text-gray-400">Crawling site for selectors and page structure...</span>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Chat input --}}
                    <div class="chat-input-area">
                        @if(!empty($chatMessages) || $conversationUlid)
                            {{-- Crawl URL input (compact, for existing conversations without crawl data) --}}
                            @if(!$this->getConversation()?->crawl_data && !$this->getProjectCrawlUrl())
                                <div class="flex gap-2 mb-2">
                                    <input
                                        type="url"
                                        wire:model="crawlUrl"
                                        placeholder="Crawl a URL for real selectors..."
                                        class="flex-1 text-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 py-1.5"
                                    />
                                    <button
                                        wire:click="crawlSite"
                                        class="px-3 py-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950 rounded-lg hover:bg-emerald-100 dark:hover:bg-emerald-900 transition disabled:opacity-50"
                                    >
                                        <span wire:loading.remove wire:target="crawlSite">Crawl</span>
                                        <span wire:loading wire:target="crawlSite">Crawling...</span>
                                    </button>
                                </div>
                            @endif
                        @endif

                        <form
                            wire:submit="sendMessage"
                            class="flex gap-2 items-end"
                        >
                            <textarea
                                wire:model="userMessage"
                                x-ref="chatInput"
                                placeholder="{{ empty($chatMessages) ? 'Describe the tests you want to generate...' : 'Ask for changes or additional tests...' }}"
                                class="chat-textarea flex-1 text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 py-2"
                                rows="1"
                                @disabled($isGenerating)
                                autofocus
                                @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.sendMessage() }"
                            ></textarea>
                            <button
                                type="submit"
                                class="shrink-0 p-2.5 text-sm font-medium text-white bg-primary-600 rounded-lg hover:bg-primary-500 transition disabled:opacity-50"
                                @disabled($isGenerating || !$projectId)
                                title="Send (Enter)"
                            >
                                <span wire:loading.remove wire:target="sendMessage">
                                    <x-heroicon-s-paper-airplane class="w-4 h-4" />
                                </span>
                                <span wire:loading wire:target="sendMessage">
                                    <x-heroicon-s-arrow-path class="w-4 h-4 animate-spin" />
                                </span>
                            </button>
                        </form>
                        <small class="text-[.6rem] text-gray-400 dark:text-gray-600 mt-1 text-right mt-2">Enter to send, Shift+Enter for new line</small>
                    </div>
                @endif
            </div>
        </div>

        {{-- ═══ Code Preview Panel (collapsible) ═══ --}}
        @if(!empty($generatedFiles))
            <div class="ai-code-panel" x-show="showCodePanel" x-transition.opacity>
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 flex flex-col h-full">

                    {{-- Code panel header --}}
                    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Generated Files</h3>
                        <div class="flex items-center gap-1">
                            <button
                                wire:click="downloadZip"
                                class="p-1.5 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition"
                                title="Download ZIP"
                            >
                                <x-heroicon-s-arrow-down-tray class="w-4 h-4" />
                            </button>
                            <button
                                @click="showCodePanel = false"
                                class="p-1.5 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition"
                                title="Close panel"
                            >
                                <x-heroicon-s-x-mark class="w-4 h-4" />
                            </button>
                        </div>
                    </div>

                    {{-- Verification status --}}
                    @if($verificationStatus)
                        <div
                            @if($verificationStatus === 'pending') wire:poll.3s="refreshVerificationStatus" @endif
                            class="mx-4 mt-3 px-3 py-2 rounded-lg text-xs font-medium flex items-start gap-2
                                @if($verificationStatus === 'passed') bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400
                                @elseif($verificationStatus === 'pending') bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400
                                @else bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400
                                @endif"
                        >
                            @if($verificationStatus === 'pending')
                                <svg class="w-3.5 h-3.5 mt-0.5 flex-shrink-0 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                            @elseif($verificationStatus === 'passed')
                                <x-heroicon-s-check-circle class="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
                            @else
                                <x-heroicon-s-exclamation-triangle class="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
                            @endif
                            <div class="flex-1 min-w-0">
                                <div>{{ $verificationLabel }}</div>
                                @if($verificationOutput && $verificationStatus !== 'passed' && $verificationStatus !== 'pending')
                                    <pre class="mt-1 whitespace-pre-wrap break-words text-[.65rem] opacity-80 max-h-24 overflow-y-auto">{{ \Illuminate\Support\Str::limit($verificationOutput, 600) }}</pre>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div x-data="{ activeFile: '{{ array_key_first($generatedFiles) }}' }" class="flex flex-col flex-1 overflow-hidden">
                        {{-- File tabs --}}
                        <div class="flex flex-wrap gap-0 px-4 pt-3 overflow-x-auto">
                            @foreach($generatedFiles as $path => $content)
                                <button
                                    class="file-tab"
                                    :class="{ 'active': activeFile === '{{ $path }}' }"
                                    @click="activeFile = '{{ $path }}'"
                                >
                                    {{ basename($path) }}
                                </button>
                            @endforeach
                        </div>

                        {{-- Code display --}}
                        <div class="flex-1 overflow-hidden px-4 pb-4">
                            @foreach($generatedFiles as $path => $content)
                                <div x-show="activeFile === '{{ $path }}'" class="code-display">{{ $content }}</div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Save as suite --}}
                    <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                        @if($this->linkedSuite)
                            <div class="flex items-center justify-between">
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    Linked to <span class="font-medium text-gray-700 dark:text-gray-300">{{ $this->linkedSuite->name }}</span>
                                </div>
                                <button
                                    wire:click="saveAsSuite"
                                    @disabled($verificationStatus === 'pending')
                                    title="{{ $verificationStatus === 'pending' ? 'Verification is still running' : '' }}"
                                    class="px-3 py-2 text-sm font-medium text-white bg-success-600 rounded-lg hover:bg-success-500 transition disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <span wire:loading.remove wire:target="saveAsSuite">{{ $verificationStatus === 'pending' ? 'Verifying…' : 'Update Suite' }}</span>
                                    <span wire:loading wire:target="saveAsSuite">Updating...</span>
                                </button>
                            </div>
                        @else
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1 block">Save as managed suite</label>
                            <div class="flex gap-2">
                                <input
                                    type="text"
                                    wire:model="suiteName"
                                    placeholder="Suite name..."
                                    class="flex-1 text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                                />
                                <button
                                    wire:click="saveAsSuite"
                                    @disabled($verificationStatus === 'pending')
                                    title="{{ $verificationStatus === 'pending' ? 'Verification is still running' : '' }}"
                                    class="px-3 py-2 text-sm font-medium text-white bg-success-600 rounded-lg hover:bg-success-500 transition disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {{ $verificationStatus === 'pending' ? 'Verifying…' : 'Save' }}
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

    </div>

    {{-- Markdown rendering libraries --}}
    <script src="https://cdn.jsdelivr.net/npm/marked@15/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked-highlight@2/lib/index.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/highlight.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/languages/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/languages/typescript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/languages/json.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/styles/github-dark.min.css" media="(prefers-color-scheme: dark)">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/styles/github.min.css" media="(prefers-color-scheme: light)">

    <script>
        // Configure marked with syntax highlighting
        (function() {
            const { markedHighlight } = globalThis.markedHighlight;
            marked.use(markedHighlight({
                langPrefix: 'hljs language-',
                highlight(code, lang) {
                    if (lang && hljs.getLanguage(lang)) {
                        return hljs.highlight(code, { language: lang }).value;
                    }
                    return hljs.highlightAuto(code).value;
                }
            }));
            marked.use({
                breaks: true,
                gfm: true,
            });
        })();

        window.renderMarkdown = function(content) {
            if (!content) return '';
            try {
                // Sanitize: strip any <script> tags from rendered output
                const html = marked.parse(content);
                const div = document.createElement('div');
                div.innerHTML = html;
                div.querySelectorAll('script,iframe,object,embed').forEach(el => el.remove());
                // Strip event handlers
                div.querySelectorAll('*').forEach(el => {
                    for (const attr of [...el.attributes]) {
                        if (attr.name.startsWith('on')) el.removeAttribute(attr.name);
                    }
                });
                return div.innerHTML;
            } catch (e) {
                // Fallback to escaped text if markdown parsing fails
                const escaped = content.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                return escaped.replace(/\n/g, '<br>');
            }
        };

        function aiLoadingMessages() {
            const messages = [
                'Thinking...',
                'Analysing your request...',
                'Generating test code...',
                'Building test structure...',
                'Crafting assertions...',
                'Almost there...',
            ];
            return {
                message: messages[0],
                _interval: null,
                _idx: 0,
                start() {
                    this._interval = setInterval(() => {
                        this._idx = (this._idx + 1) % messages.length;
                        this.message = messages[this._idx];
                    }, 3000);
                },
                stop() {
                    clearInterval(this._interval);
                },
                destroy() {
                    clearInterval(this._interval);
                }
            };
        }

        document.addEventListener('livewire:init', () => {
            // Background wire:poll ticks (verification/recording status)
            // re-render the whole component every few seconds and touch
            // #chat-messages even when nothing in the chat changed — only
            // force-scroll on those if the user was already at the bottom,
            // so reading an older message isn't interrupted by polling.
            let stickToBottom = true;

            document.addEventListener('scroll', (e) => {
                const el = document.getElementById('chat-messages');
                if (!el || e.target !== el) return;
                stickToBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
            }, true);

            Livewire.hook('morph.updated', ({el}) => {
                if (el.id === 'chat-messages' && stickToBottom) {
                    el.scrollTop = el.scrollHeight;
                }
            });

            // An explicit scroll-chat dispatch means the user just did
            // something (sent a message, generated, converted frameworks) —
            // always jump to bottom for that, regardless of scroll position.
            Livewire.on('scroll-chat', () => {
                stickToBottom = true;
                setTimeout(() => {
                    const el = document.getElementById('chat-messages');
                    if (el) el.scrollTop = el.scrollHeight;
                }, 50);
            });

            // Scroll to bottom on initial page load if messages exist
            requestAnimationFrame(() => {
                const el = document.getElementById('chat-messages');
                if (el && el.children.length > 1) el.scrollTop = el.scrollHeight;
            });
        });
    </script>

</x-filament-panels::page>
