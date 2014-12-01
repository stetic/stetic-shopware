{block name='frontend_index_header_javascript' append}
<script>
{literal}
var _fss=_fss||{}; _fss.token = '{/literal}{$SteticConfig->site_token}{literal}';
{/literal}{$steticIdentify}{literal}
(function(){var e="stetic",a=window,c=["track","identify","config","register","unregister"],b=function(){var d=0,f=this;for(f._fs=[],d=0;c.length>d;d++){(function(j){f[j]=function(){return f._fs.push([j].concat(Array.prototype.slice.call(arguments,0))),f}})(c[d])}};a[e]=a[e]||new b;a.fourstats=a.fourstats||new b;var i=document;var h=i.createElement("script");h.type="text/javascript";h.async=true;h.src="//stetic.com/t.js";var g=i.getElementsByTagName("script")[0];g.parentNode.insertBefore(h,g)})();
{/literal}{$steticEvents}{literal}
{/literal}
</script>
{/block}

{block name="frontend_index_header_javascript_inline" append}
{*<script type="text/javascript">*}
  $(window).load(function() {
	  (function(){
		  
		  var controller = {$steticController|json_encode},
		  	  data = {$steticData|json_encode};
			  
			  if( controller == 'detail' )
			  {
			  	$('#basketButton').bind('click.stetic', function(e) {
					
					var quantity = $('#sQuantity').val(),
					    product = data.product;
						
						product.quantity = quantity;
						
						fourstats.track('basket', { product:product });
				});
			  }

	  })(); 
  });
{*</script>*}
{/block}
