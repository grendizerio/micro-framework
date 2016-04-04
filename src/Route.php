<?php

namespace Grendizer\MicroFramework;

use Grendizer\Container\Container;
use Grendizer\Container\ContainerInterface;
use Grendizer\HttpMessage\ResponseInterface;
use Grendizer\HttpMessage\ServerRequestInterface;
use Grendizer\MicroFramework\Exception\NotFoundException;
use Grendizer\MicroFramework\Exception\RouteParameterException;
use Grendizer\MicroFramework\Helper\Arr;

class Route
{
    /**
     * @var \Grendizer\Container\ContainerInterface
     */
    protected $container;

    /**
     * 路由名称
     * 
     * @var string
     */
    protected $name;

    /**
     * 路由表达式
     * 
     * @var string
     */
    protected $exprsion;

    /**
     * 路由表达式转化的正则表达式
     * 
     * @var string
     */
    protected $pattern;

    /**
     * 路由表达式转化成的模板
     * 
     * @var string
     */
    protected $template;

    /**
     * 路由参数规则
     * 
     * @var array
     */
    protected $paramRules;

    /**
     * 通过匹配`url`得到的参数表
     * 
     * @var array
     */
    protected $parameters;

    /**
     * Route constructor.
     *
     * @param  string  $exprsion
     * @param string  $name
     * @param  \Grendizer\Container\ContainerInterface  $container
     *
     * @throws \Exception
     */
    public function __construct($exprsion, $name, ContainerInterface $container = null)
    {
        if (null === $container) {
            $container = Container::getInstance();

            if (!$container) {
                throw new \Exception('Bad container');
            }
        }

        $this->container = $container;

        $this->name = $name;
        $this->exprsion = $exprsion;
        $this->parameters = array();

        $this->parse($exprsion);
    }

    public function __get($name)
    {
        if (isset($this->{$name})) {
            return $this->{$name};
        }

        return null;
    }

    public function __set($name, $value)
    {
        // TODO: Implement __set() method.
    }

    /**
     * 解析路由表达式
     * 
     * @param  string  $exprsion
     * @throws \Grendizer\MicroFramework\Exception\RouteParameterException
     */
    protected function parse($exprsion)
    {
        // 不包含规则的情况下
        if (false === strpos($exprsion, '<')) {
            $this->pattern = sprintf('#^%s$#', preg_quote($exprsion, '#'));
            return;
        }

        // 这个 $tr[] 用于字符串的转换
        $tr = array(
            '.' => '\\.',
            '*' => '\\*',
            '$' => '\\$',
            '[' => '\\[',
            ']' => '\\]',
            '(' => '\\(',
            ')' => '\\)',
        );

        // pattern 中含有 <参数名:参数值规则> ，
        // 其中 ':参数值规则' 部分是可选的。
        if (preg_match_all('/<(\w+):?([^>]+)?>/', $exprsion, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                // 获取 “参数名”
                $name = $match[1][0];

                // 获取 “参数值规则” ，如果未指定，
                // 使用 '[^\/]' ，表示匹配除 '/' 外的所有字符
                $pattern = isset($match[2][0]) ? $match[2][0] : '';
                $rule = $pattern ?: '[^\/]+';


                $tr["<$name>"] = sprintf("(?P<%s>%s)", $name, $rule);

                // TODO： 这里暂不考虑重名参数的需求？
                if (isset($this->paramRules[$name])) {
                    $message = 'Cannot redeclare route-parameter "%s"';
                    throw new RouteParameterException(sprintf($message, $name));
                }
                
                // 保存`参数值规则`，用于后期的数据校验
                $this->paramRules[$name] = $pattern ? sprintf("#^%s$#u", $pattern) : null;
            }
        }

        // 将 <参数名:参数值规则> 替换成 <参数名> ，
        // 作为模板用于生成符合规则的 URI
        $template = preg_replace('/<(\w+):?([^>]+)?>/', '<$1>', $exprsion);

        $this->template = $template;

        // 将 template 中的特殊字符及字符串使用 tr[] 进行转换，并作为最终的真正表达式
        $this->pattern = sprintf('#^%s$#u', rtrim(strtr($template, $tr), '/'));
    }

    /**
     * 匹配给定的URL
     *
     * @param  string  $url
     * @param  array  $defaults
     * @return bool
     */
    public function match($url, array $defaults = array())
    {
        // 当前URL是否匹配规则，留意这个pattern是经过 init() 转换的
        if (preg_match($this->pattern, $url, $matches)) {
            // 遍历规则定义的默认参数，如果当前URL中未定义，则忽略。
            foreach ($defaults as $name=>$value) {
                if (isset($matches[$name]) && $matches[$name] === '') {
                    $matches[$name] = $value;
                }
            }

            foreach ($matches as $name=>$value) {
                $this->parameters[$name] = $value;
            }

            return true;
        }

        return false;
    }

    /**
     * 根据路由生成URL
     *
     * @param  Route  $route
     * @param  string  $expresion
     * @param  array  $parameters
     * @return  string|static
     * @throws RouteParameterException
     */
    protected static function generate(Route $route, $expresion, array $parameters = array())
    {
        $tr = array();

        // 如果传入的路由与规则定义的路由不一致，
        // 如 post/view 与 post/<action> 并不一致
        if ($expresion !== $route->exprsion) {
            $route->parse($expresion);
        }

        $settings = $route->container['settings'];
        $encode = $settings->get('url.encode', true);

        // 遍历所有的参数匹配规则
        foreach ($route->paramRules as $name => $rule) {

            // 如果 $params 传入了同名参数，且该参数不是数组，且该参数匹配规则，
            // 则使用该参数匹配规则作为转换规则，并从 $parameters 中去掉该参数
            if (isset($parameters[$name])) {
                if (!is_array($parameters[$name])
                    && (!$rule || preg_match($rule, $parameters[$name]))) {
                    $value = $parameters[$name];

                    if ($encode) {
                        $value = urlencode($value);
                    }

                    $tr["<$name>"] = $value;
                    
                    // 销毁使用过的数据
                    // 防止出现在`querystring`中
                    unset($parameters[$name]);

                    continue;
                }
                
                $message = 'Does not conform to the rules "%s" of parameter "%s"';
                throw new RouteParameterException($message, $rule, $name);
            }

            // 否则一旦没有设置该参数的默认值或 $params 提供了该参数，
            // 说明规则又不匹配了
            if (isset($route->parameters[$name])) {
                $tr["<$name>"] = $route->parameters[$name];
                continue;
            }

            throw new RouteParameterException('Miss the parameter "'.$name.'"');
        }

        // 使用 $tr 对 $_template 时行转换，并去除多余的 '/'
        $url = strtr($route->template, $tr);
        $url = preg_replace('#/+#', '/', $url);
        $url = rtrim($url, '/');

        return $route
            ->container
            ->resolve('request')
            ->getUri()
            ->withPath($url.$settings->get('url.format'))
            ->withQuery(http_build_query($parameters));
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param ContainerInterface $container
     * @return ResponseInterface|mixed|null
     * @throws NotFoundException
     */
    public static function resolve(ServerRequestInterface $request, ResponseInterface $response, ContainerInterface $container)
    {
        $url = $request->getUri()->getPath();
        $setting = $container['settings'];
        $router = $container['router'];
        $result = null;

        while($router->valid()) {
            $route = $router->key();
            $method = $router->current();

            if (!is_string($route)) {
                $route = $method;
                $method = null;
            }

            $route = static::make($route, null, $container);

            if (!$route->match($url)) {
                $router->next();
                continue;
            }

            $params = $route->parameters;

            if (!$method) {
                $method = Arr::get($params, 'controller', $setting->get('controler.default'));
                $method.= $setting->get('controller.suffix', 'Controller').'@';
                $method.= Arr::get($params, 'action', $setting->get('action.default'));
                $method.= $setting->get('action.suffix', 'Action');
            }

            Arr::forget($params, 'controller');
            Arr::forget($params, 'action');

            $result = $container->call($method, array($params));
            
            break;
        }

        if (!$result) {
            throw new NotFoundException($request, $response);
        }

        if (!$result instanceof ResponseInterface) {
            $response->getBody()->write((string)$result);
            return $response;
        }

        return $result;
    }


    public static function make($route, $name = null, ContainerInterface $container = null)
    {
        return new static($route, $name?:$route, $container);
    }
}
