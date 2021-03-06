<?php

/**
 * Controller designed to wrap around calls to defined services
 * 
 * To call a service, use jsonservice/servicename/methodname
 * 
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class WebServiceController extends Controller {
	
	/**
	 * Disable all public requests by default; If this is 
	 * set to true, services must still explicitly allow public access
	 * on those services that can be called by non-auth'd users. 
	 *
	 * @var boolean
	 */
	private static $allow_public_access = false;
	
	/**
	 * List of object -> json converter classes
	 *
	 * @var array
	 */
	protected $converters = array();
	
	protected $format = 'json';
	
	/**
	 * Whether allowing access to the API by passing a security ID after
	 * logging in. 
	 *
	 * @var boolean
	 */
	public static $allow_security_id = true;
	
	public static $dependencies = array(
		'tokenAuthenticator'	=> '%$TokenAuthenticator',
		'injector'				=> '%$Injector',
	);
	
	public $tokenAuthenticator;
	public $injector;

	public function init() {
		parent::init();
		$this->converters['json'] = array(
			'DataObject'		=> new DataObjectJsonConverter(),
			'DataObjectSet'		=> new DataObjectSetJsonConverter(),
			'DataList'			=> new DataObjectSetJsonConverter(),
			'Array'				=> new ArrayJsonConverter(),
			'ScalarItem'		=> new ScalarJsonConverter(),
			'stdClass'			=> new ScalarJsonConverter(),
			'FinalConverter'	=> new FinalJsonConverter()
		);

		$this->converters['xml'] = array(
			'ScalarItem'		=> new ScalarXmlConverter(),
			'FinalConverter'	=> new FinalXmlConverter()
		);

		if (strpos($this->request->getURL(), 'xmlservice') === 0) {
			$this->format = 'xml';
		}
	}

	public function handleRequest(SS_HTTPRequest $request, DataModel $model) {
		try {
			$this->pushCurrent();
			$token = $this->getToken($request);
			if ((!Member::currentUserID() && !self::$allow_public_access) || $token) {
				if (!$token) {
					throw new WebServiceException(403, "Missing token parameter");
				}
				$user = $this->tokenAuthenticator->authenticate($token);
				if (!$user) {
					throw new WebServiceException(403, "Invalid user token");
				}
			} else if (self::$allow_security_id) {
				// we check the SecurityID parameter
				$secParam = SecurityToken::inst()->getName();
				$securityID = $request->requestVar($secParam);
				if ($securityID != SecurityToken::inst()->getValue()) {
					throw new WebServiceException(403, "Invalid security ID");
				}
			} else if (!self::$allow_public_access) {
				throw new WebServiceException(403, "Invalid request");
			}
			$response = parent::handleRequest($request, $model);
			
			if (self::has_curr()) {
				$this->popCurrent();
			}
			
			if ($response instanceof SS_HTTPResponse) {
				$response->addHeader('Content-Type', 'application/'.$this->format);
			}
			return $response;
		} catch (WebServiceException $exception) {
			$this->response = new SS_HTTPResponse();
			$this->response->setStatusCode($exception->status);
			$this->response->setBody($this->ajaxResponse($exception->getMessage(), $exception->status));
		} catch (SS_HTTPResponse_Exception $e) {
			$this->response = $e->getResponse();
			$this->response->setBody($this->ajaxResponse($e->getMessage(), $e->getCode()));
		} catch (Exception $exception) {
			$code = 500;
			// check type explicitly in case the Restricted Objects module isn't installed
			if (class_exists('PermissionDeniedException') && $exception instanceof PermissionDeniedException) {
				$code = 403;
			}

			$this->response = new SS_HTTPResponse();
			$this->response->setStatusCode($code);
			$this->response->setBody($this->ajaxResponse($exception->getMessage(), $code));
		}
		
		return $this->response;
	}
	
	protected function getToken(SS_HTTPRequest $request) {
		$token = $request->requestVar('token');
		if (!$token) {
			$token = $request->getHeader('X-Auth-Token');
		}
		
		return $token;
	}

	/**
	 * Calls to webservices are routed through here and converted
	 * from url params to method calls. 
	 * 
	 * @return mixed
	 */
	public function index() {
		$service = ucfirst($this->request->param('Service')) . 'Service';
		$method = $this->request->param('Method');

		$body = $this->request->getBody();
		
		$requestType = strlen($body) > 0 ? 'POST' : 'GET';
		
		$svc = $this->injector->get($service);

		if ($svc && ($svc instanceof WebServiceable || method_exists($svc, 'webEnabledMethods'))) {
			$allowedMethods = array();
			if (method_exists($svc, 'webEnabledMethods')) {
				$allowedMethods = $svc->webEnabledMethods();
			}

			// if we have a list of methods, lets use those to restrict
			if (count($allowedMethods)) {
				$this->checkMethods($method, $allowedMethods, $requestType);
			} else {
				// we only allow 'read only' requests so we wrap everything
				// in a readonly transaction so that any database request
				// disallows write() calls
				// @TODO
			}
			
			if (!Member::currentUserID()) {
				// require service to explicitly state that the method is allowed
				if (method_exists($svc, 'publicWebMethods')) {
					$publicMethods = $svc->publicWebMethods();
					if (!isset($publicMethods[$method])) {
						throw new WebServiceException(403, "Public method $method not allowed");
					}
				} else {
					throw new WebServiceException(403, "Method $method not allowed; no public methods defined");
				}
			}

			$refObj = new ReflectionObject($svc);
			$refMeth = $refObj->getMethod($method);
			/* @var $refMeth ReflectionMethod */
			if ($refMeth) {
				$allArgs = $this->request->requestVars();
				unset($allArgs['url']);
				
				if (strlen($body) && !count($allArgs)) {
					// decode the body to a params array
					$bodyParams = Convert::json2array($body);
					if (isset($bodyParams['params'])) {
						$allArgs = $bodyParams['params'];
					} else {
						$allArgs = $bodyParams;
					}
				}

				$refParams = $refMeth->getParameters();
				$params = array();
				
				foreach ($refParams as $refParm) {
					/* @var $refParm ReflectionParameter */
					$paramClass = $refParm->getClass();
					// if we're after a dataobject, we'll try and find one using
					// this name with ID and Type parameters
					if ($paramClass && ($paramClass->getName() == 'DataObject' || $paramClass->isSubclassOf('DataObject'))) {
						$idArg = $refParm->getName().'ID';
						$typeArg = $refParm->getName().'Type';
						
						if (isset($allArgs[$idArg]) && isset($allArgs[$typeArg]) && class_exists($allArgs[$typeArg])) {
							$object = null;
							if (class_exists('DataService')) {
								$object = $this->injector->DataService->byId($allArgs[$typeArg], $allArgs[$idArg]);
							} else {
								$object = DataObject::get_by_id($allArgs[$typeArg], $allArgs[$idArg]);
								if (!$object->canView()) {
									$object = null;
								}
							}
							if ($object) {
								$params[$refParm->getName()] = $object;
							}
						}
					} else if (isset($allArgs[$refParm->getName()])) {
						$params[$refParm->getName()] = $allArgs[$refParm->getName()];
					} else if ($refParm->isOptional()) {
						$params[$refParm->getName()] = $refParm->getDefaultValue();
					} else {
						throw new WebServiceException(500, "Service method $method expects parameter " . $refParm->getName());
					}
				}
				
				$return = $refMeth->invokeArgs($svc, $params);
				
				$responseItem = $this->convertResponse($return);
				return $this->converters[$this->format]['FinalConverter']->convert($responseItem);
			}
		}
	}
	
	/**
	 * Check the allowed methods for access rights
	 * 
	 * @param array $allowedMethods
	 * @throws WebServiceException 
	 */
	protected function checkMethods($method, $allowedMethods, $requestType) {
		if (!isset($allowedMethods[$method])) {
			throw new WebServiceException(403, "You do not have permission to $method");
		}

		$info = $allowedMethods[$method];
		$allowedType = $info;
		if (is_array($info)) {
			$allowedType = isset($info['type']) ? $info['type'] : '';
			
			if (isset($info['perm'])) {
				if (!Permission::check($info['perm'])) {
					throw new WebServiceException(403, "You do not have permission to $method");
				}
			}
		}
		
		// otherwise it might be the wrong request type
		if ($requestType != $allowedType) {
			throw new WebServiceException(405, "$method does not support $requestType");
		}
	}

	/**
	 * Converts the given object to something appropriate for a response
	 */
	public function convertResponse($return) {
		if (is_object($return)) {
			$cls = get_class($return);
		} else if (is_array($return)) {
			$cls = 'Array';
		} else {
			$cls = 'ScalarItem';
		}

		if (isset($this->converters[$this->format][$cls])) {
			return $this->converters[$this->format][$cls]->convert($return, $this);
		}

		// otherwise, check the hierarchy if the class actually exists
		if (class_exists($cls)) {
			$hierarchy = array_reverse(array_keys(ClassInfo::ancestry($cls)));
			foreach ($hierarchy as $cls) {
				if (isset($this->converters[$this->format][$cls])) {
					return $this->converters[$this->format][$cls]->convert($return, $this);
				}
			}
		}

		return $return;
	}
	
	/**
	 * Indicate whether public users can access web services in general
	 *
	 * @param boolean $value 
	 */
	public static function set_allow_public($value) {
		self::$allow_public_access = $value;
	}
	
	protected function ajaxResponse($message, $status) {
		return Convert::raw2json(array(
			'message' => $message,
			'status' => $status,
		));
	}
	
}

class WebServiceException extends Exception {
	public $status;
	
	public function __construct($status=403, $message='', $code=null, $previous=null) {
		$this->status = $status;
		parent::__construct($message, $code, $previous);
	}
}

class ScalarJsonConverter {
	public function convert($value) {
		return Convert::raw2json($value);
	}
}

class ScalarXmlConverter {
	public function convert($value) {
		return $value;
	}
}

class FinalJsonConverter {
	public function convert($value) {
		$return = '{"response": '.$value . '}';
		return $return;
	}
}

class FinalXmlConverter {
	public function convert($value) {
		$return = '<response>'.Convert::raw2xml($value).'</response>';
		return $return;
	}
}