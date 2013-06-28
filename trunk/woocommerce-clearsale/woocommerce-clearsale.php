<?php
/*
Plugin Name: WooCommerce ClearSale
Plugin URI: http://www.wooplugins.com.br
Description: Faz a integração do WooCommerce com o sistema anti-fraude da ClearSale
Version: 0.1
Author: F2M Tecnologia <contato@f2mtecnologia.com.br>
Author URI: http://www.f2mtecnologia.com.br
License: Commercial
Requires at least: 3.4
Tested up to: 3.5.2
*/


class F2M_ClearSale {
    public function __construct(){
        if(is_admin()){
			//create the clearsale menu on admin panel
			add_action('admin_menu', array($this, 'add_plugin_page'));
			//register all clearsale main settings
			add_action('admin_init', array($this, 'page_init'));
			//create the action buttons in the order page
			add_action('woocommerce_order_actions_end', array($this, 'f2m_clearsale_order_options')); 
			//execute the action selected in the order page
			add_action('save_post', array($this, 'f2m_clearsale_process_options')); 
			
			//all hooks here will be used to create or update the status of the order with ClearSale
			add_action('woocommerce_new_order', array($this, 'f2m_clearsale_process_new_order')); 
			add_action('woocommerce_order_status_changed', array($this, 'f2m_clearsale_process_order_status')); 
			add_action('woocommerce_payment_complete', array($this, 'f2m_clearsale_process_order_payment')); 
		}
    }
	
    public function add_plugin_page(){
        // This page will be under "Settings"
		add_options_page('ClearSale', 'ClearSale', 'manage_options', 'f2m-clearsale-settings', array($this, 'create_admin_page'));
    }

    public function create_admin_page(){
        ?>
			<div class="wrap">
				<?php screen_icon(); ?>
				<h2>Configurações do ClearSale</h2>			
				<form method="post" action="options.php">
					<?php
						// This prints out all hidden setting fields
						settings_fields('f2m_clearsale_settings');	
						do_settings_sections('f2m_clearsale_section_main');
						submit_button(); 
					?>
				</form>
			</div>
		<?php
    }
	
    public function page_init(){		
		register_setting('f2m_clearsale_settings', 'f2m_clearsale_settings');
        add_settings_section('setting_section_id','Setting', array($this, 'print_section_info'), 'f2m_clearsale_section_main');	
		add_settings_field('f2m_clearsale_field_token', 'Código de Integração', array($this, 'create_token_field'), 'f2m_clearsale_section_main', 'setting_section_id');		
		add_settings_field('f2m_clearsale_field_url', 'URL', array($this, 'create_url_field'), 'f2m_clearsale_section_main', 'setting_section_id');		
    }
	
    public function print_section_info(){
		print 'Digite as informações abaixo para configurar o ClearSale:';
    }
	
    public function create_token_field(){
		$options = get_option('f2m_clearsale_settings');
		echo "<input id='f2m_clearsale_field_token' name='f2m_clearsale_settings[f2m_clearsale_field_token]' size='40' type='text' value='{$options['f2m_clearsale_field_token']}' />";
    }	
	
    public function create_url_field(){
		$options = get_option('f2m_clearsale_settings');
		echo "<input id='f2m_clearsale_field_url' name='f2m_clearsale_settings[f2m_clearsale_field_url]' size='40' type='text' value='{$options['f2m_clearsale_field_url']}' />";
    }
	
	public function f2m_clearsale_order_options($order_id){
		$settings = get_option('f2m_clearsale_settings');
	
		$actions  = "<li class='wide'><table>";
		$actions .= 	"<tr><h3>ClearSale</h3>";
		$actions .= 		"<td><input type='submit' style='width:120px;' class='button tips' name='f2m_clearsale_submit'   value='Atualizar'   data-tip='Atualiza o status do pedido no ClearSale.' /> </td>";
		//TODO: make image from clearsale work
		$actions .= 		"<td><!--<iframe src='http://homologacao.clearsale.com.br/integracaov2/FreeClearSale/frame.aspx?CodigoIntegracao=".$settings['f2m_clearsale_field_token']."&PedidoID=".$order_id."' width='280' height='85' frameborder='0' scrolling='no'><P>Seu Browser não suporta iframes</P></iframe>--></td>";
		$actions .= 	"</tr>";
		$actions .= "</table></li>";
		echo $actions;
	}
	
	public function f2m_clearsale_process_options($post_id){
		// don't run the echo if this is an auto save
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE )
			return;
		
		// don't run the echo if the function is called for saving revision.
		$post_object = get_post( $post_id );
		if ( $post_object->post_type == 'revision' )
			return;

		if(isset($_POST['f2m_clearsale_submit']) && $_POST['f2m_clearsale_submit']){
			remove_action('save_post', 'f2m_clearsale_process_options');

				global $woocommerce;

				//get the plugin settings
				$settings = get_option('f2m_clearsale_settings');

				//create woocommerce order object
				$order = &new WC_Order( $post_id );
				
				// We are here so lets check status and do actions
				$order->add_order_note( __('felipe', 'woothemes') );

			add_action('save_post', 'f2m_clearsale_process_options');
		}
	}
	
	public function f2m_clearsale_process_new_order(){
		//TODO: process a new order, submitting the information to clearsale
	}
	
	public function f2m_clearsale_process_order_status(){
		//TODO: process an order status change, updating clearsale data
	}
	
	public function f2m_clearsale_process_order_payment(){
		//TODO: process an order with a complete payment status, updating clearsale data
	}
	
}

$f2m_clearsale = new F2M_ClearSale();

?>