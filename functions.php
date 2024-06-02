<?php

//Register post type
//
$namespace = 'giocheria/api';


add_filter( 'wp_is_application_passwords_available', '__return_true' );
if( !function_exists('giornale_articoli_post_type') ){
	function giornale_articoli_post_type() {
		$args = array(
			'public'    => true,
			'label'     => __( 'Articoli', 'textdomain' ),
			'supports' => array('custom-fields',  'title', 'editor', 'author', 'thumbnail', 'comments.')
		);
		register_post_type( 'articolo', $args );
	}
	add_action( 'init', 'giornale_articoli_post_type' );
}

add_action('init', function(){
	register_rest_route( 'giornale/api', '/v1/accedi', array(
		'methods' => WP_REST_SERVER::CREATABLE,
		'callback' => 'accedi'
	));
});

add_action('init', function(){
	register_rest_route( 'giornale/api', '/v1/articoli', array(
		'methods' => WP_REST_SERVER::CREATABLE,
		'callback' => 'post_articolo'
	));
});

add_action('init', function(){
	register_rest_route( 'giornale/api', '/v1/articoli', array(
		'methods' => WP_REST_SERVER::READABLE,
		'callback' => 'get_articoli'
	));
});

add_action('init', function(){
	register_rest_route( 'giornale/api', '/v1/articoli', array(
		'methods' => WP_REST_SERVER::EDITABLE,
		'callback' => 'patch_articolo'
	));
});

add_action('init', function(){
	register_rest_route( 'giornale/api', '/v1/articoli', array(
		'methods' => WP_REST_SERVER::DELETABLE,
		'callback' => 'remove_articolo'
	));
});

//return wp_user/false
function serializza_utente($id){
	$utente = get_user_by('ID', $id);

	if(!$utente){
		return false;
	}

	//$user->roles[0]
	$utente_ser['id'] = $utente->ID;
	$utente_ser['email'] = $utente->user_email;
	$utente_ser['nome_utente'] = $utente->user_login;
	$utente_ser['nome'] = $utente->first_name;
	$utente_ser['cognome'] = $utente->last_name;
	$utente_ser['nome_pubblico'] = $utente->display_name;

	if ($utente->roles[0] == 'subscriber')
		$utente_ser['ruolo'] = 0;
	else if($utente->roles[0] == 'author')
		$utente_ser['ruolo'] = 1;
	else if($utente->roles[0] == 'administrator')
		$utente_ser['ruolo'] = 2;
	else
		$utente_ser['ruolo'] = -1;

	return $utente_ser;
}

//return articolo/false
function serializza_articolo($id){
	/*$args = array(
		'post_type' => 'articolo',
		'post_in' => array($id)
	);*/
	#
	$articolo = get_post($id);
	
	if(!$articolo){
		return false;
	}

	$articolo_ser['id'] = $articolo -> ID;
	$articolo_ser['creato'] = $articolo -> post_date;
	$articolo_ser['creato_da'] = intval($articolo -> post_author);
	if($articolo->post_status === 'publish')
		$articolo_ser['pubblicato'] = true;
	else
		$articolo_ser['pubblicato'] = false;
	$articolo_ser['titolo'] = $articolo -> post_title;
	$articolo_ser['testo'] = $articolo -> post_content;
	$articolo_ser['note'] = get_post_meta($articolo->ID, 'note', true);


	
	return $articolo_ser;
}

//return id/wp_error
function autenticazione($aut_head){
	if ( empty($aut_head) )
        return new WP_Error( 'rest_forbidden', __( 'Authentication required.' ), array( 'status' => 401 ) );
	
	$info_utente = explode(':', base64_decode(str_replace('Basic ', '', $aut_head)));

	if(count($info_utente) < 2)
		return new WP_Error( 'rest_forbidden', __( 'Authentication required.' ), array( 'status' => 401 ) );


	$info_utente[0] = sanitize_text_field(wp_unslash($info_utente[0]));//id
	if(ctype_digit($info_utente[0]))
		$info_utente[0] = intval($info_utente[0]);
	else
		return new WP_Error( 'rest_forbidden', __( 'Authentication required.' ), array( 'status' => 401 ) );

	$info_utente[1] = sanitize_text_field(wp_unslash($info_utente[1]));//application password

	$utente = get_user_by('ID', $info_utente[0]);

	if (!$utente)
		return new WP_Error( 'rest_forbidden', __( 'Authentication required.' ), array( 'status' => 401 ) );

	
	$ap = wp_authenticate_application_password(null, $utente->user_login, $info_utente[1]);

	if(is_null($ap))
		return new WP_Error( 'rest_forbidden', __( 'Authentication required.' ), array( 'status' => 401 ) );
	else if(is_wp_error($ap))
		return new WP_Error( 'rest_forbidden', __( 'Authentication error.' ), array( 'status' => 403 ) );

	return $info_utente[0];

}

//return wp_rest_response/wp_error
function accedi($request){

	$richiesti = array('email', 'password');

	$parametri = $request->get_body_params();
	foreach($parametri as $chiave => $valore){

		if (!in_array($chiave, $richiesti)){
			$risposta['messaggio'] = 'parametri mancanti';
			return new WP_REST_Response($risposta);
		}
	}
	
	$email = sanitize_text_field($parametri['email']);
	if (isset($email)){

		$pass = sanitize_text_field($parametri['password']);
		if (isset($pass)){

			$user = get_user_by('email', $email);
			$result = wp_check_password($pass, $user->user_pass, $user->ID);
			if ($result){
				$aps = WP_Application_Passwords::get_user_application_passwords($user->ID);
				foreach($aps as $ap){
					WP_Application_Passwords::delete_application_password( $user->ID, $ap['uuid'] );
				}

				$app_pass = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => 'giornale' ) );

				$utente = serializza_utente($user->ID);
				if(!$utente)
					return new WP_Error( 'rest_forbidden', __( 'Authentication error.' ), array( 'status' => 403 ) );

				$risposta['token'] = $app_pass[0];
				$risposta['utente'] = $utente;

				return new WP_REST_Response($risposta);
			}
		}
	}
	return new WP_Error( 'rest_forbidden', __( 'Authentication required.' ), array( 'status' => 401 ) );
}

function post_articolo($request){

	$id_utente = autenticazione($request->get_header('Authorization'));

	if(is_wp_error($id_utente)){
		return $id_utente;
	}

	$utente_ser = serializza_utente($id_utente);
	if ($utente_ser['ruolo'] < 1){
		return new WP_Error( 'rest_forbidden', __( 'Authentication error.' ), array( 'status' => 403 ) );
	}

	$richiesti = array('titolo', 'descrizione', 'pubblicato', 'note');

	$parametri = $request->get_body_params();
	foreach($parametri as $chiave => $valore){

		if (!in_array($chiave, $richiesti)){
			$risposta['messaggio'] = 'parametri mancanti';
			return new WP_REST_Response($risposta);
		}
	}
	
	$post['post_type'] = 'articolo';
	$pubblicato = sanitize_text_field(wp_unslash($parametri['pubblicato'])) === 'true'? true : false;
	
	if($pubblicato){
		$post['post_status'] = 'publish';	
	}
	else{
		$post['post_status'] = 'private';
	}

	$post['post_title'] = sanitize_text_field($parametri['titolo']);
	$post['post_content'] = sanitize_text_field($parametri['descrizione']);
	$post['post_author'] = $id_utente;

	$post_id = wp_insert_post($post);
	if (!is_wp_error( $post_id )){

		add_post_meta( $post_id, 'note', sanitize_text_field($parametri['note']), true );

		return new WP_REST_Response(serializza_articolo($post_id));
	}

}

function get_articoli($request){
	$tutti_ports = 40;
	$cerca_ports = 40;

	$id_utente = autenticazione($request->get_header('Authorization'));

	if(is_wp_error($id_utente)){
		return $id_utente;
	}


	if ($_GET === []){
		
		$args = array(
			'numberports' => $tutti_ports,
			'post_type' => 'articolo'
		);

		$articoli = [];
		foreach(get_posts($args) as $articolo){
			array_push($articoli, serializza_articolo($articolo->ID));
		}

		return $articoli;
	}

	if(isset($_GET['id'])){

		$id = sanitize_text_field(wp_unslash($_GET['id']));

		if(ctype_digit($id)){
			$id = intval($id);
			$articolo =  serializza_articolo($id);
			if($articolo !== false)
				return $articolo;
		}
	}
	else if(isset($_GET['cerca'])){
		$cerca = sanitize_text_field($_GET['cerca']);

		$args = array(
			"numberports" => $cerca_ports,
			"post_type" => "articolo",
			"s" => $cerca
		);

		$articoli = [];

		foreach(get_posts($args) as $articolo){
			array_push($articoli, serializza_articolo($articolo->ID));
		}

		return $articoli;
	}

	return new WP_Error( 'not_found', __( 'resource not found.' ), array( 'status' => 404 ) );
}

function patch_articolo($request){
	$id_utente = autenticazione($request->get_header('Authorization'));

	if(is_wp_error($id_utente)){
		return $id_utente;
	}

	$utente_ser = serializza_utente($id_utente);
	if ($utente_ser['ruolo'] < 1){
		return new WP_Error( 'rest_forbidden', __( 'Authentication error.' ), array( 'status' => 403 ) );
	}

	if (isset($_GET['id'])){
		$id_articolo = sanitize_text_field(wp_unslash($_GET['id']));

		if(ctype_digit($id_articolo))
			$id_articolo = intval($id_articolo);
		else{
			$risposta['messaggio'] = 'id mancante';
			return new WP_REST_Response($risposta);
		}

		$articolo = serializza_articolo($id_articolo);
		if($articolo === false){
			$risposta['messaggio'] = 'articolo non esistente';
			return new WP_REST_Response($risposta);
		}

		if($utente_ser['ruolo'] === 1){
			if($articolo['creato_da'] !== $utente_ser['id'])
				return new WP_Error( 'rest_forbidden', __( 'Authentication error.' ), array( 'status' => 403 ) );
		}

		#array('titolo', 'descrizione', 'pubblicato', 'note');
		$parametri = $request->get_body_params();
		
		$post['ID'] = $id_articolo;

		if(isset($parametri['titolo']))
			$post['post_title'] = sanitize_text_field($parametri['titolo']);
		if(isset($parametri['descrizione']))
			$post['post_content'] = sanitize_text_field($parametri['descrizione']);
		if(isset($parametri['pubblicato'])){
			$pubblicato = sanitize_text_field(wp_unslash($parametri['pubblicato'])) === 'true'? true : false;

			if($pubblicato){
				$post['post_status'] = 'publish';	
			}
			else{
				$post['post_status'] = 'private';
			}
		}
		if(isset($parametri['note'])){
			$post['meta_input'] = [
				'note' => sanitize_text_field($parametri['note'])
			];
		}

		$articolo_modificato = wp_update_post($post, true);

		return serializza_articolo($articolo_modificato, true);
	}

	$risposta['messaggio'] = 'id mancante';
	return new WP_REST_Response($risposta);
}

function remove_articolo($request){
	$id_utente = autenticazione($request->get_header('Authorization'));

	if(is_wp_error($id_utente)){
		return $id_utente;
	}

	$utente_ser = serializza_utente($id_utente);
	if ($utente_ser['ruolo'] < 1){
		return new WP_Error( 'rest_forbidden', __( 'Authentication error.' ), array( 'status' => 403 ) );
	}

	if (isset($_GET['id'])){
		$id_articolo = sanitize_text_field(wp_unslash($_GET['id']));

		if(ctype_digit($id_articolo))
			$id_articolo = intval($id_articolo);
		else{
			$risposta['messaggio'] = 'id mancante';
			return new WP_REST_Response($risposta);
		}

		$articolo = serializza_articolo($id_articolo);
		if($articolo === false){
			$risposta['messaggio'] = 'articolo non esistente';
			return new WP_REST_Response($risposta);
		}

		if($utente_ser['ruolo'] === 1){
			if($articolo['creato_da'] !== $utente_ser['id'])
				return new WP_Error( 'rest_forbidden', __( 'Authentication error.' ), array( 'status' => 403 ) );
		}
	}

	$deleted_post = wp_delete_post( $id_articolo );
		if( !empty( $deleted_post ) ){
			$response['status'] =  200;	
			$response['success'] = true;
			$response['data'] = $deleted_post;	
		}else{
			$response['status'] =  200;	
		   	$response['success'] = false;
		    $response['message'] = 'No post found!';	
		}
}

