<?php if (!defined('BASEPATH')) exit('No direct script access allowed');
/**
 * Game AdminPanel (АдминПанель)
 *
 * @package		Game AdminPanel
 * @author		Nikita Kuznetsov (ET-NiK)
 * @copyright	Copyright (c) 2014, Nikita Kuznetsov (http://hldm.org)
 * @license		http://www.gameap.ru/license.html
 * @link		http://www.gameap.ru
 * @filesource
 */
 
/**
 * Смена RCON пароля пользователем
 * 
 * Контроллер позволяет менять RCON пароль обычным пользователям.
 * Пароль меняется в базе и в конфигурационных файлах сервера.
 * 
 * @package		Game AdminPanel
 * @category	Controllers
 * @author		Nikita Kuznetsov (ET-NiK)
 */
 
class Rcon_changer extends MX_Controller {
	public $tpl_data = array();
	
	public function __construct()
    {
		parent::__construct();
		
		$this->load->model('users');
		$this->load->model('servers');
		
		if (!$this->users->check_user()) {
			 redirect('auth');
		}
		
		$this->load->library('form_validation');
		
		$this->lang->load('rcon_changer');
		$this->lang->load('server_command');
		$this->lang->load('server_control');
		
		$this->tpl_data['title'] 	= lang('rcon_changer_title');
		$this->tpl_data['heading'] 	= lang('rcon_changer_heading');
		
		$this->tpl_data['content'] = '';
		$this->tpl_data['menu'] = $this->parser->parse('menu.html', $this->tpl_data, true);
		$this->tpl_data['profile'] = $this->parser->parse('profile.html', $this->users->tpl_userdata(), true);
	}
	
	// ----------------------------------------------------------------

    /**
     * Отображение информационного сообщения
    */ 
    function _show_message($message = FALSE, $link = FALSE, $link_text = FALSE)
    {
        
        if (!$message) {
			$message = lang('error');
		}
		
        if (!$link) {
			$link = 'javascript:history.back()';
		}
		
		if (!$link_text) {
			$link_text = lang('back');
		}

        $local_tpl['message'] = $message;
        $local_tpl['link'] = $link;
        $local_tpl['back_link_txt'] = $link_text;
        $this->tpl_data['content'] = $this->parser->parse('info.html', $local_tpl, TRUE);
        $this->parser->parse('main.html', $this->tpl_data);
    }
    
    // ----------------------------------------------------------------
	
	/**
	 * Страница смены Rcon пароля
	 */
	public function change($server_id = false, $confirm = false)
	{
		$this->load->driver('rcon');
		$this->load->helper('form');

		if (!$server_id) {
			$this->_show_message(lang('server_control_empty_server_id'));
			return;
		}
		
		$this->servers->server_data = $this->servers->get_server_data($server_id);
		$this->users->get_server_privileges($server_id);
		
		if(!$this->servers->server_data) {
			$this->_show_message(lang('server_control_server_not_found'));
			return false;
		}
		
		// Должна быть привилегия на смену Rcon пароля
		if(!$this->users->auth_servers_privileges['CHANGE_RCON']) {
			$this->_show_message(lang('server_control_server_not_found'));
			return false;
		}
		
		$local_tpl = array();
		$local_tpl['server_id'] = $server_id;
		
		$this->form_validation->set_rules('rcon_password', lang('rcon_changer_password'), 'trim|required|alpha_numeric|min_length[6]|max_length[64]|xss_clean');
		
		if ($this->form_validation->run() == false) {
			
			if (validation_errors()) {
				$this->_show_message(validation_errors());
				return false;
			}
			
			$this->tpl_data['content'] = $this->parser->parse('rcon_change.html', $local_tpl, true);
		} else {
			$this->rcon->set_variables(
				$this->servers->server_data['server_ip'],
				$this->servers->server_data['server_port'],
				$this->servers->server_data['rcon'], 
				$this->servers->server_data['engine'],
				$this->servers->server_data['engine_version']
			);

			$new_rcon = $this->input->post('rcon_password');
			
			try {
				// Смена пароля, правка файлов
				$this->rcon->change_rcon($new_rcon);
				
				// Редактирование данных в БД
				$sql_data = array('rcon' => $new_rcon);
				$this->servers->edit_game_server($this->servers->server_data['id'], $sql_data);
				
				if ($this->input->post('restart_server')) {
					// Перезагрузка сервера
					$command = replace_shotcodes($this->servers->command_generate($this->servers->server_data, 'restart'), $this->servers->server_data);
					send_command($command, $this->servers->server_data);
				}
				
				// Отправка сообщения пользователю
				$this->_show_message(lang('rcon_changer_success'), site_url('admin/server_control/main/' . $server_id), lang('next'));
				
				// Данные в лог
				$log_data['log_data'] = "OldPassword: {$this->servers->server_data['rcon']} NewPassword: {$new_rcon}";
				
			} catch (Exception $e) {
				$log_data['msg'] 		= lang('rcon_changer_fail');
				
				// Отправка сообщения
				// Если пользователь админ, то ему будут отображены дополнительные данные ошибки
				if ($this->users->auth_data['is_admin']) {
					$this->_show_message(lang('rcon_changer_fail') . ' ' . $e->getMessage());
				} else {
					$this->_show_message(lang('rcon_changer_fail'));
				}
				
				// Данные в лог
				$log_data['log_data'] 	= $e->getMessage();
			}
			
			// Записываем логи
			$log_data['type'] 			= 'rcon_changer';
			$log_data['command'] 		= 'change_rcon';
			$log_data['server_id'] 		= $server_id;
			$log_data['user_name'] 		= $this->users->auth_login;
			$this->panel_log->save_log($log_data);
			
			return;
		}

		$this->parser->parse('main.html', $this->tpl_data);
	}
	
}
