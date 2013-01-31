<?php

/**
 *
 * This class handles the processing of json-rpc request
 * @author Matt Morley (MPCM)
 *
 */
class jsonrpc2 {
	static function error( $code, $version = '2.0', $id = null ) {
		// declare the specification error codes
		$errors = array (
			-32600 => 'Invalid Request',
			-32601 => 'Method not found',
			-32602 => 'Invalid params',
			-32603 => 'Internal error',
			-32700 => 'Parse error',
		);

		return (object) array(
			'id' => $id,
			'jsonrpc' => $version,
			'error' => (object) array(
				'code' => $code,
				'message' => $errors[$code],
			),
		);
	}

	// an array of method routes, set in the constructor
	private $method_map = array();

	/**
	 * create an instance of the jsonrpc2 class, and map in the allowed method strings
	 * @param array $method_map
	 */
	public function __construct( array $method_map ) {
		$this->method_map = $method_map;
	}

	public function isValidRequestObect( $request ) {
		// per the 2.0 specification
		// a request object must:
		// be an object
		if ( !is_object( $request ) )
			return false;

		// contain a jsonrpc member that is a string
		if ( !isset( $request->jsonrpc ) || $request->jsonrpc !== '2.0' )
			return false;

		// contain a method member that is a string
		if ( !isset( $request->method ) || !is_string( $request->method ) )
			return false;

		// if it contains a params member
		//    that member must be an array or an object
		if (
			isset( $request->params )
			&& !is_array( $request->params )
			&& !is_object( $request->params )
		)
			return false;

		// if it contains an id member
		//    that member must be a string, number, or null
		if (
			isset( $request->id )
			&& !is_string( $request->id )
			&& !is_numeric( $request->id )
			&& !is_null( $request->id )
		)
			return false;

		// it passes the tests
		return true;
	}

	public function dispatch( $request ) {
		try {
			// decode the string, if passed as a string
			if ( is_string( $request ) )
				$request = json_decode( $request );

			// JSON failed to parse
			if ( json_last_error() > 0 )
				return jsonrpc2::error( -32700 );

			// if we are passed an array of requests
			if ( is_array ( $request ) ) {
				// make sure it is a numeric array
				if ( jsonrpc2::isAssoc( $request ) )
					return jsonrpc2::error( -32600 );

				// create a holder for all the responses
				$return = array();

				//for each request as a request object
				foreach ( $request as $request_object ) {
					// process the single request
					$return[] = $this->dispatch_single( $request_object );

					// remove the last request if somehow it is not an object
					if ( !is_object( end( $return ) ) )
						array_pop( $return );
				}

				// if there are no results (all notifications)
				if ( count( $return ) == 0 )
					$return = null;

				// return the array of results
				return $return;
			}

			// process the request
			return $this->dispatch_single( $request );
		} catch ( Exception $e ) {
			return jsonrpc2::error( -32603 );
		}
	}

	/**
	 * process a single request object
	 * @param request object
	 */
	public function dispatch_single( $request ) {
		// check that the object passes some basic protocal shape tests
		if ( !$this->isValidRequestObect( $request ) )
			return jsonrpc2::error( -32600 );

		// if the request object does not specify a jsonrpc verison
		if ( !isset( $request->jsonrpc ) )
			$request->jsonrpc = '1.0';

		// if the request is 2.0 and and no params were sent
			// create an empty params entry,
			// as 2.0 requests do not need to send an empty array
			// later code can now assume that this field will exist
		if ( $request->jsonrpc == '2.0' && !isset( $request->params ) )
			$request->params = array();

		// invoke the request object, and store it in the reponse
		$response = $this->invoke( $request );

		// if the request id is not set, or if it is null
		if ( !isset ( $request->id ) || is_null( $request->id ) )
			return null;

		// copy the request id into the response object
		$response->id = $request->id;

		// if it is a 2.0 request
		if ( $request->jsonrpc === '2.0' ) {
			// set the response to 2.0
			$response->jsonrpc = $request->jsonrpc;
		} else {
			// assume it is a 1.0 requrest
			// ensure there is a result member in the response
			if ( !isset( $response->result ) )
				$response->result = null;

			// ensure there is an error member
			if ( !isset( $response->error ) )
				$response->error = null;
		}

		// return the response object
		return $response;
	}

	// take a more complete request, after processing, and invoke it after checking the parameters align
	// extend this function if you need to provide more automatic actions related to methods in classes/instanes
	private function invoke( $request ) {
		// if the method requested is available
		if ( isset( $this->method_map[$request->method] ) ) {
			try {
				// reflect the global function or method
				$name = $this->method_map[$request->method];
				if ( is_array( $name ) ) {
					$reflection = new ReflectionMethod ( $name[0], $name[1] );
					$object = is_object( $name[0] ) ? $name[0] : null;
				} elseif ( strpos( $name, '::' ) ) {
					list( $class, $method ) = explode( '::', $name, 2 );
					$reflection = new ReflectionMethod ( $class, $method );
					$object = null;
				} else {
					$reflection = new ReflectionFunction ( $name );
					$object = false;
				}

				// check the parameters in the reflection against what was sent in the request
				$params = $this->checkParams( $reflection->getParameters(), $request->params );

				if ( $object === false )
					$result = $reflection->invokeArgs( $params );
				else
					$result = $reflection->invokeArgs( $object, $params );

				// return the result as an invoked call
				return (object) array( 'result' => $result );
			} catch ( Exception $e ) {
				// if anything abnormal happened, capture the error code thrown
				$error = $e->getMessage();
			}
		}
		// by this point, all we have is errors
		return jsonrpc2::error( isset($error) ? $error : -32601 );
	}

	private function isAssoc( array $arr ) {
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	private function checkParams( $real, $sent ) {
		// create the param list we are going to use to invoke the object
		$new = array();

		$is_obj = is_object( $sent );
		$is_assoc = is_array( $sent ) && jsonrpc2::isAssoc( $sent );

		// check every parameter
		foreach ( $real as $i => $param ) {
			$name = $param->getName();
			if ( $is_obj && isset( $sent->{$name} ) ) {
				$new[$i] = $sent->{$name};
			} elseif ( $is_assoc && $sent[$name] ) {
				$new[$i] = $sent[$name];
			} elseif ( isset( $sent[$i] ) ) {
				$new[$i] = $sent[$i];
			} elseif ( !$param->isOptional() ) {
				throw new Exception( -32602 );
			}
		}

		// return the list of matching params
		return $new;
	}
}
