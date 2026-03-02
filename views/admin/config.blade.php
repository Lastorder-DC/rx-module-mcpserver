@include('header')

<form class="x_form-horizontal" action="./" method="post" id="mcpserver">
	<input type="hidden" name="module" value="mcpserver" />
	<input type="hidden" name="act" value="procMcpserverAdminInsertConfig" />
	<input type="hidden" name="success_return_url" value="{{ getRequestUriByServerEnviroment() }}" />
	<input type="hidden" name="xe_validator_id" value="modules/mcpserver/views/admin/config/1" />

	@if (!empty($XE_VALIDATOR_MESSAGE) && $XE_VALIDATOR_ID == 'modules/mcpserver/views/admin/config/1')
		<div class="message {{ $XE_VALIDATOR_MESSAGE_TYPE }}">
			<p>{{ $XE_VALIDATOR_MESSAGE }}</p>
		</div>
	@endif

	<div class="x_alert x_alert-info">
		<p>{!! $lang->mcpserver_config_description !!}</p>
		<p>{{ $lang->mcpserver_config_restart_notice }}</p>
	</div>

	<section class="section">
		<h1>{{ $lang->mcpserver_section_server_basic }}</h1>
		
		<div class="x_control-group">
			<label class="x_control-label" for="serverName">{{ $lang->mcpserver_server_name }}</label>
			<div class="x_controls">
				<input type="text" name="serverName" id="serverName" value="{{ $config->serverName ?? 'MCP Server' }}" class="x_form-control" />
				<p class="x_help-block">{{ $lang->mcpserver_server_name_help }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="serverVersion">{{ $lang->mcpserver_server_version }}</label>
			<div class="x_controls">
				<input type="text" name="serverVersion" id="serverVersion" value="{{ $config->serverVersion ?? '1.0.0' }}" class="x_form-control" />
				<p class="x_help-block">{{ $lang->mcpserver_server_version_help }}</p>
			</div>
		</div>
	</section>

	<section class="section">
		<h1>{{ $lang->mcpserver_section_server_connection }}</h1>

		<div class="x_alert x_alert-warning">
			<p>{{ $lang->mcpserver_connection_warning }}</p>
		</div>
		
		<div class="x_control-group">
			<label class="x_control-label" for="serverHost">{{ $lang->mcpserver_server_host }}</label>
			<div class="x_controls">
				<input type="text" name="serverHost" id="serverHost" value="{{ $config->serverHost ?? '127.0.0.1' }}" class="x_form-control" />
				<p class="x_help-block">{{ $lang->mcpserver_server_host_help }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="serverPort">{{ $lang->mcpserver_server_port }}</label>
			<div class="x_controls">
				<input type="number" name="serverPort" id="serverPort" value="{{ $config->serverPort ?? 8080 }}" class="x_form-control" min="1" max="65535" />
				<p class="x_help-block">{{ $lang->mcpserver_server_port_help }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="mcpPath">{{ $lang->mcpserver_mcp_path }}</label>
			<div class="x_controls">
				<input type="text" name="mcpPath" id="mcpPath" value="{{ $config->mcpPath ?? '/mcp' }}" class="x_form-control" />
				<p class="x_help-block">{{ $lang->mcpserver_mcp_path_help }}</p>
			</div>
		</div>
	</section>

	<section class="section">
		<h1>{{ $lang->mcpserver_section_mcp_options }}</h1>
		
		<div class="x_control-group">
			<label class="x_control-label" for="mcpSSEEnable">{{ $lang->mcpserver_sse_enable }}</label>
			<div class="x_controls">
				<select name="mcpSSEEnable" id="mcpSSEEnable">
					<option value="Y" @selected($config->mcpSSEEnable ?? false)>{{ $lang->mcpserver_enable }}</option>
					<option value="N" @selected(!($config->mcpSSEEnable ?? false))>{{ $lang->mcpserver_disable }}</option>
				</select>
				<p class="x_help-block">{{ $lang->mcpserver_sse_help }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="mcpStateless">{{ $lang->mcpserver_stateless_mode }}</label>
			<div class="x_controls">
				<select name="mcpStateless" id="mcpStateless">
					<option value="Y" @selected($config->mcpStateless ?? false)>{{ $lang->cmd_yes }}</option>
					<option value="N" @selected(!($config->mcpStateless ?? false))>{{ $lang->cmd_no }}</option>
				</select>
				<p class="x_help-block">{{ $lang->mcpserver_stateless_help }}</p>
				@if (\Rhymix\Framework\Config::get('cache.type') === 'dummy' && !$config->mcpStateless)
				<div class="message error">
					<p>{!! $lang->mcpserver_cache_warning !!}</p>
				</div>
				@endif
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="disableExampleMethods">{{ $lang->mcpserver_disable_example_methods }}</label>
			<div class="x_controls">
				<select name="disableExampleMethods" id="disableExampleMethods">
					<option value="Y" @selected($config->disableExampleMethods ?? false)>{{ $lang->cmd_yes }}</option>
					<option value="N" @selected(!($config->disableExampleMethods ?? false))>{{ $lang->cmd_no }}</option>
				</select>
				<p class="x_help-block">{{ $lang->mcpserver_disable_example_methods_help }}</p>
			</div>
		</div>
	</section>

	<section class="section">
		<h1>{{ $lang->mcpserver_section_oauth }}</h1>

		<div class="x_alert x_alert-info">
			<p>{!! $lang->mcpserver_oauth_description !!}</p>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="oauthEnabled">{{ $lang->mcpserver_oauth_enable }}</label>
			<div class="x_controls">
				<select name="oauthEnabled" id="oauthEnabled">
					<option value="Y" @selected($config->oauthEnabled ?? false)>{{ $lang->mcpserver_enable }}</option>
					<option value="N" @selected(!($config->oauthEnabled ?? false))>{{ $lang->mcpserver_disable }}</option>
				</select>
				<p class="x_help-block">{{ $lang->mcpserver_oauth_enable_help }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="oauthPublicUrl">{{ $lang->mcpserver_oauth_public_url }}</label>
			<div class="x_controls">
				<input type="text" name="oauthPublicUrl" id="oauthPublicUrl" value="{{ $config->oauthPublicUrl ?? '' }}" class="x_form-control" placeholder="{{ $lang->mcpserver_oauth_public_url_placeholder }}" />
				<p class="x_help-block">{{ $lang->mcpserver_oauth_public_url_help }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="oauthPassword">{{ $lang->mcpserver_oauth_password }}</label>
			<div class="x_controls">
				<input type="password" name="oauthPassword" id="oauthPassword" value="" class="x_form-control" placeholder="{{ $lang->mcpserver_oauth_password_placeholder }}" autocomplete="new-password" />
				<p class="x_help-block">{{ $lang->mcpserver_oauth_password_help }}</p>
				@if ($config->oauthEnabled && empty($config->oauthPassword))
				<div class="message error">
					<p>{{ $lang->mcpserver_oauth_password_required }}</p>
				</div>
				@endif
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->mcpserver_oauth_clients_title }}</label>
			<div class="x_controls">
				@if (!empty($oauthClients))
				<table class="x_table" style="width:100%; border-collapse:collapse; margin-top:5px;">
					<thead>
						<tr>
							<th style="text-align:left; padding:8px; border-bottom:2px solid #ddd;">{{ $lang->mcpserver_oauth_client_name }}</th>
							<th style="text-align:left; padding:8px; border-bottom:2px solid #ddd;">{{ $lang->mcpserver_oauth_client_id }}</th>
							<th style="text-align:left; padding:8px; border-bottom:2px solid #ddd;">{{ $lang->mcpserver_oauth_client_grant_types }}</th>
							<th style="text-align:left; padding:8px; border-bottom:2px solid #ddd;">{{ $lang->mcpserver_oauth_client_auth_method }}</th>
							<th style="text-align:left; padding:8px; border-bottom:2px solid #ddd;">{{ $lang->mcpserver_oauth_client_created_at }}</th>
							<th style="text-align:left; padding:8px; border-bottom:2px solid #ddd;">{{ $lang->mcpserver_oauth_client_actions }}</th>
						</tr>
					</thead>
					<tbody>
						@foreach ($oauthClients as $client)
						<tr>
							<td style="padding:8px; border-bottom:1px solid #eee;">{{ $client['client_name'] ?? '-' }}</td>
							<td style="padding:8px; border-bottom:1px solid #eee;"><code>{{ $client['client_id'] }}</code></td>
							<td style="padding:8px; border-bottom:1px solid #eee;">{{ implode(', ', $client['grant_types'] ?? []) }}</td>
							<td style="padding:8px; border-bottom:1px solid #eee;">{{ $client['token_endpoint_auth_method'] ?? 'none' }}</td>
							<td style="padding:8px; border-bottom:1px solid #eee;">{{ isset($client['created_at']) ? date('Y-m-d H:i', $client['created_at']) : '-' }}</td>
							<td style="padding:8px; border-bottom:1px solid #eee;">
								@if (!empty($client['client_secret']))
								<button type="button" class="x_btn x_btn-sm" onclick="mcpserverRegenerateSecret('{{ $client['client_id'] }}')">{{ $lang->mcpserver_oauth_regenerate_secret }}</button>
								@endif
								<button type="button" class="x_btn x_btn-sm x_btn-danger" onclick="mcpserverDeleteClient('{{ $client['client_id'] }}')">{{ $lang->mcpserver_oauth_delete_client }}</button>
							</td>
						</tr>
						@endforeach
					</tbody>
				</table>
				@else
				<p>{{ $lang->mcpserver_oauth_no_clients }}</p>
				@endif
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->mcpserver_oauth_register_title }}</label>
			<div class="x_controls">
				<div style="background:#f8f9fa; border:1px solid #ddd; border-radius:8px; padding:20px; margin-top:5px;">
					<div class="x_control-group" style="margin-bottom:12px;">
						<label for="oauth_client_name"><strong>{{ $lang->mcpserver_oauth_client_name }}</strong></label>
						<input type="text" id="oauth_client_name" class="x_form-control" placeholder="{{ $lang->mcpserver_oauth_register_name_placeholder }}" style="margin-top:4px;" />
					</div>
					<div class="x_control-group" style="margin-bottom:12px;">
						<label for="oauth_redirect_uris"><strong>{{ $lang->mcpserver_oauth_register_redirect_uris }}</strong></label>
						<textarea id="oauth_redirect_uris" class="x_form-control" rows="3" placeholder="{{ $lang->mcpserver_oauth_register_redirect_uris_placeholder }}" style="margin-top:4px;"></textarea>
						<p class="x_help-block">{{ $lang->mcpserver_oauth_register_redirect_uris_help }}</p>
					</div>
					<div class="x_control-group" style="margin-bottom:12px;">
						<label><strong>{{ $lang->mcpserver_oauth_client_grant_types }}</strong></label>
						<div style="margin-top:4px;">
							<label class="x_form-check" style="margin-right:15px;">
								<input type="checkbox" id="oauth_grant_authorization_code" checked /> authorization_code
							</label>
							<label class="x_form-check" style="margin-right:15px;">
								<input type="checkbox" id="oauth_grant_client_credentials" /> client_credentials
							</label>
							<label class="x_form-check">
								<input type="checkbox" id="oauth_grant_refresh_token" checked /> refresh_token
							</label>
						</div>
					</div>
					<div class="x_control-group" style="margin-bottom:12px;">
						<label for="oauth_auth_method"><strong>{{ $lang->mcpserver_oauth_client_auth_method }}</strong></label>
						<select id="oauth_auth_method" class="x_form-control" style="margin-top:4px;">
							<option value="none">none</option>
							<option value="client_secret_post">client_secret_post</option>
							<option value="client_secret_basic">client_secret_basic</option>
						</select>
					</div>
					<button type="button" class="x_btn x_btn-primary" onclick="mcpserverRegisterClient()">{{ $lang->mcpserver_oauth_register_btn }}</button>
				</div>
			</div>
		</div>
	</section>

	<section class="section">
		<h1>{{ $lang->mcpserver_section_log }}</h1>
		
		<div class="x_control-group">
			<label class="x_control-label" for="printLog">{{ $lang->mcpserver_log_output }}</label>
			<div class="x_controls">
				<select name="printLog" id="printLog">
					<option value="Y" @selected($config->printLog ?? true)>{{ $lang->cmd_yes }}</option>
					<option value="N" @selected(!($config->printLog ?? true))>{{ $lang->cmd_no }}</option>
				</select>
				<p class="x_help-block">{{ $lang->mcpserver_log_help }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->mcpserver_log_level }}</label>
			<div class="x_controls">
				<div class="x_form-check-list">
					<label class="x_form-check">
						<input type="checkbox" name="logLevel_emergency" value="Y" @checked($config->printLogLevels['emergency'] ?? true) />
						{{ $lang->mcpserver_log_emergency }}
					</label>
					<label class="x_form-check">
						<input type="checkbox" name="logLevel_alert" value="Y" @checked($config->printLogLevels['alert'] ?? true) />
						{{ $lang->mcpserver_log_alert }}
					</label>
					<label class="x_form-check">
						<input type="checkbox" name="logLevel_critical" value="Y" @checked($config->printLogLevels['critical'] ?? true) />
						{{ $lang->mcpserver_log_critical }}
					</label>
					<label class="x_form-check">
						<input type="checkbox" name="logLevel_error" value="Y" @checked($config->printLogLevels['error'] ?? true) />
						{{ $lang->mcpserver_log_error }}
					</label>
					<label class="x_form-check">
						<input type="checkbox" name="logLevel_warning" value="Y" @checked($config->printLogLevels['warning'] ?? true) />
						{{ $lang->mcpserver_log_warning }}
					</label>
					<label class="x_form-check">
						<input type="checkbox" name="logLevel_notice" value="Y" @checked($config->printLogLevels['notice'] ?? true) />
						{{ $lang->mcpserver_log_notice }}
					</label>
					<label class="x_form-check">
						<input type="checkbox" name="logLevel_info" value="Y" @checked($config->printLogLevels['info'] ?? true) />
						{{ $lang->mcpserver_log_info }}
					</label>
					<label class="x_form-check">
						<input type="checkbox" name="logLevel_debug" value="Y" @checked($config->printLogLevels['debug'] ?? true) />
						{{ $lang->mcpserver_log_debug }}
					</label>
				</div>
				<p class="x_help-block">{{ $lang->mcpserver_log_level_help }}</p>
			</div>
		</div>
	</section>

	<div class="btnArea x_clearfix">
		<button type="submit" class="x_btn x_btn-primary x_pull-right" style="margin-right: 10px;">{{ $lang->cmd_registration }}</button>
	</div>

</form>

<script>
function mcpserverRegenerateSecret(clientId) {
	if (!confirm('{{ $lang->mcpserver_oauth_regenerate_secret_confirm }}')) return;
	exec_json('mcpserver.procMcpserverAdminRegenerateClientSecret', { client_id: clientId }, function(res) {
		if (res.error == 0 && res.client_secret) {
			alert('{{ $lang->mcpserver_oauth_new_secret }}' + ':\n\n' + res.client_secret + '\n\n' + '{{ $lang->mcpserver_oauth_new_secret_warning }}');
		}
	});
}
function mcpserverDeleteClient(clientId) {
	if (!confirm('{{ $lang->mcpserver_oauth_delete_client_confirm }}')) return;
	exec_json('mcpserver.procMcpserverAdminDeleteOAuthClient', { client_id: clientId, success_return_url: location.href }, function(res) {
		if (res.error == 0) {
			location.reload();
		}
	});
}
function mcpserverRegisterClient() {
	var clientName = document.getElementById('oauth_client_name').value.trim();
	if (!clientName) {
		alert('{{ $lang->mcpserver_oauth_register_name_required }}');
		return;
	}
	var redirectUris = document.getElementById('oauth_redirect_uris').value.trim();
	var grantTypes = [];
	if (document.getElementById('oauth_grant_authorization_code').checked) grantTypes.push('authorization_code');
	if (document.getElementById('oauth_grant_client_credentials').checked) grantTypes.push('client_credentials');
	if (document.getElementById('oauth_grant_refresh_token').checked) grantTypes.push('refresh_token');
	var authMethod = document.getElementById('oauth_auth_method').value;

	exec_json('mcpserver.procMcpserverAdminRegisterOAuthClient', {
		oauth_client_name: clientName,
		oauth_redirect_uris: redirectUris,
		oauth_grant_types: grantTypes,
		oauth_auth_method: authMethod,
		success_return_url: location.href
	}, function(res) {
		if (res.error == 0) {
			var msg = 'Client ID: ' + res.client_id;
			if (res.client_secret) {
				msg += '\nClient Secret: ' + res.client_secret + '\n\n' + '{{ $lang->mcpserver_oauth_new_secret_warning }}';
			}
			alert(msg);
		}
	});
}
</script>
