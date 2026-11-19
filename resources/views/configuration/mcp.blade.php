{{-- Connecting AI agents to the MCP server with a personal token. --}}
@php
    $mcpUrl = url('mcp');
    $token = session('mcp_token');
    $shownToken = $token ?? '<your token>';
@endphp
<section class="box mcp-setup" id="mcp">
    <h2 class="subtitle is-5">MCP for agents</h2>
    <p>
        Connect an AI agent to <code>{{ $mcpUrl }}</code>. Given a ticket number, it gets the Jira ticket, the ticket's
        open pull requests with their diffs and comments, and the <a href="{{ url('files') }}">files</a> for those repositories.
        Comments it adds are saved as your unsent comments, under the agent's model name, for you to check and send.
    </p>

    @if($token)
        <div class="notification is-warning is-light">
            <p><strong>Your new token</strong> — copy it now, it won't be shown again:</p>
            <pre class="mcp-token">{{ $token }}</pre>
        </div>
    @endif

    <div class="buttons are-small">
        <form action="{{ url('configuration/mcp-token') }}" method="post"
              @if(auth()->user()->mcp_token) onsubmit="return confirm('Replace your token? Agents using the old one stop working.')" @endif>
            @csrf
            <button class="button is-primary" type="submit">{{ auth()->user()->mcp_token ? 'Replace token' : 'Create token' }}</button>
        </form>
        @if(auth()->user()->mcp_token)
            <form action="{{ url('configuration/mcp-token') }}" method="post" onsubmit="return confirm('Remove your token?')">
                @csrf
                @method('DELETE')
                <button class="button is-danger is-light" type="submit">Remove token</button>
            </form>
            @unless($token) <span class="tag is-success is-light">You have a token</span> @endunless
        @endif
    </div>

    <details class="mcp-commands" @if($token) open @endif>
        <summary>How to add it to an agent</summary>
        <p class="mcp-command-label">Claude Code</p>
        <pre>claude mcp add --transport http reviewer {{ $mcpUrl }} --header "Authorization: Bearer {{ $shownToken }}"</pre>
        <p class="mcp-command-label">Codex (<code>~/.codex/config.toml</code>, token in the <code>REVIEWER_MCP_TOKEN</code> environment variable)</p>
        <pre>[mcp_servers.reviewer]
url = "{{ $mcpUrl }}"
bearer_token_env_var = "REVIEWER_MCP_TOKEN"</pre>
        <p class="mcp-command-label">Other agents (Cursor, VS Code, …) — JSON config</p>
        <pre>{
  "mcpServers": {
    "reviewer": {
      "url": "{{ $mcpUrl }}",
      "headers": { "Authorization": "Bearer {{ $shownToken }}" }
    }
  }
}</pre>
        <p class="help">Then ask, for example: <em>Review ticket ABC-123 with the reviewer MCP and add your comments.</em></p>
    </details>
</section>
