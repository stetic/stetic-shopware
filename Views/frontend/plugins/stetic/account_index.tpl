{block name='frontend_account_index_newsletter_settings' append}
	<div class="doublespace">&nbsp;</div>
	
	<div class="grid_16 newsletter first last">
		<form name="frmRegister" method="post" action="{url action=index}">
			<input type="hidden" name="steticAction" value="save">
			<div class="inner_container">
				<div class="form">
					<fieldset>
			        	<p>
			        		<input class="auto_submit" type="checkbox" name="allowidentify" value="1" id="allowidentify" {if $userAllowidentify}checked="checked"{/if} class="chkbox" style="float: left;" />
			        		<label for="allowidentify" class="chklabel" style="width: 90%; margin-left: 5px;">
			        			{s namespace="frontend/register/index" name='RegisterLabelIdentifyCheckbox'}Ich erkläre mich damit einverstanden, dass meine angegebenen Daten wie Benutzer-ID, Name, E-Mail-Adresse und Firmenname zur Webanalyse an stetic.com übermittelt und für statistische Zwecke verwendet werden.{/s}
			        		</label>
			        	</p>
			        </fieldset>
			    </div>
		    </div>
		</form>
	</div>
{/block}