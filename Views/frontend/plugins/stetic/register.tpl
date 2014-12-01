{block name='frontend_register_index_input_privacy' append}
<div class="privacy">
	<input name="register[personal][allowidentify]" type="checkbox" id="allowidentify"{if $smarty.post.allowidentify} checked="checked"{/if} value="1" class="chkbox" style="float:left;" />
	<label for="allowidentify" class="chklabel">{s namespace="frontend/register/index" name='RegisterLabelIdentifyCheckbox'}Ich erkläre mich damit einverstanden, dass meine angegebenen Daten wie Benutzer-ID, Name, E-Mail-Adresse und Firmenname zur Webanalyse an stetic.com übermittelt und für statistische Zwecke verwendet werden.{/s}</label>
	<div class="clear">&nbsp;</div>
</div>
{/block}
