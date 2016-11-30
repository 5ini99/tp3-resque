ThinkPHP3.2 集成 php-resque: PHP Resque Worker
===========================================

php-resque是php环境中一个轻量级的队列服务。具体队列服务是做什么用的，请自行百度！

## 运行环境 ##

* PHP 5.2+
* Redis 2.2+

## 集成方法 ##

### 将源码放到ThinkPHP的Vendor目录中 ###

将源码更新到 ThinkPHP/Library/Vendor/php-resque/ 目录中

### 在项目根目录中创建resque入口脚本 ###

	#!/usr/bin/env php
	<?php
	ini_set('display_errors', true);
	error_reporting(E_ERROR);
	set_time_limit(0);
	
	define('MODE_NAME', 'cli');	// 自定义cli模式
	define('BIND_MODULE', 'Home');	// 绑定到Home模块
	define('BIND_CONTROLLER', 'Queue');	// 绑定到Queue控制器
	define('BIND_ACTION', 'index');	// 绑定到index方法
	
	// 处理自定义参数
	$act = $argv[1] ?? 'start';
	putenv("Q_ACTION={$act}");
	putenv("Q_ARGV=" . json_encode($argv));
	
	require './ThinkPHP/ThinkPHP.php';

### 创建Queue控制器 ###

在`Home`模块的`Controller`中创建`Queue`控制器

	<?php
	namespace Home\Controller;
	
	if (!IS_CLI)  die('The file can only be run in cli mode!');
	use Exception;
	use Resque;
	
	/***
	 * queue入口
	 * Class Worker
	 * @package Common\Controller
	 */
	class QueueController
	{
	    protected $vendor;
	    protected $args = [];
	    protected $keys = [];
	    protected $queues = '*';
	
	    public function __construct()
	    {
	        vendor('php-resque.autoload');
	        $argv = json_decode(getenv('Q_ARGV'));
	        foreach ($argv as $item) {
	            if (strpos($item, '=')) {
	                list($key, $val) = explode('=', $item);
	            } else {
	                $key = $val = $item;
	            }
	            $this->keys[] = $key;
	            $this->args[$key] = $val;
	        }
	
	        $this->init();
	    }
	
	    /**
	     * 执行队列
	     * 环境变量参数值：
	     * --queue|QUEUE: 需要执行的队列的名字
	     * --interval|INTERVAL：在队列中循环的间隔时间，即完成一个任务后的等待时间，默认是5秒
	     * --app|APP_INCLUDE：需要自动载入PHP文件路径，Worker需要知道你的Job的位置并载入Job
	     * --count|COUNT：需要创建的Worker的数量。所有的Worker都具有相同的属性。默认是创建1个Worker
	     * --debug|VVERBOSE：设置“1”启用更啰嗦模式，会输出详细的调试信息
	     * --pid|PIDFILE：手动指定PID文件的位置，适用于单Worker运行方式
	     */
	    private function init()
	    {
	        $is_sington = false; //是否单例运行，单例运行会在tmp目录下建立一个唯一的PID
	
	        // 根据参数设置QUEUE环境变量
	        $QUEUE = in_array('--queue', $this->keys) ? $this->args['--queue'] : '*';
	        if (empty($QUEUE)) {
	            die("Set QUEUE env var containing the list of queues to work.\n");
	        }
	        $this->queues = explode(',', $QUEUE);
	
	        // 根据参数设置INTERVAL环境变量
	        $interval = in_array('--interval', $this->keys) ? $this->args['--interval'] : 5;
	        putenv("INTERVAL={$interval}");
	
	        // 根据参数设置COUNT环境变量
	        $count = in_array('--count', $this->keys) ? $this->args['--count'] : 1;
	        putenv("COUNT={$count}");
	
	        // 根据参数设置APP_INCLUDE环境变量
	        $app = in_array('--app', $this->keys) ? $this->args['--app'] : '';
	        putenv("APP_INCLUDE={$app}");
	
	        // 根据参数设置PIDFILE环境变量
	        $pid = in_array('--pid', $this->keys) ? $this->args['--pid'] : '';
	        putenv("PIDFILE={$pid}");
	
	        // 根据参数设置VVERBOSE环境变量
	        $debug = in_array('--debug', $this->keys) ? $this->args['--debug'] : '';
	        putenv("VVERBOSE={$debug}");
	    }
	
	    public function index()
	    {
	        $act = getenv('Q_ACTION');
	        switch ($act) {
	            case 'stop':
	                $this->stop();
	                break;
	            case 'status':
	                $this->status();
	                break;
	            default:
	                $this->start();
	        }
	    }
	
	    /**
	     * 开始队列
	     */
	    public function start()
	    {
	        // 载入任务类
	        $path = COMMON_PATH . "Job";
	        $flag = \FilesystemIterator::KEY_AS_FILENAME;
	        $glob = new \FilesystemIterator($path, $flag);
	        foreach ($glob as $file) {
	            if('php' === pathinfo($file, PATHINFO_EXTENSION))
	                require realpath($file);
	        }
	
	        $logLevel = 0;
	        $LOGGING = getenv('LOGGING');
	        $VERBOSE = getenv('VERBOSE');
	        $VVERBOSE = getenv('VVERBOSE');
	        if (!empty($LOGGING) || !empty($VERBOSE)) {
	            $logLevel = Resque\Worker::LOG_NORMAL;
	        } else {
	            if (!empty($VVERBOSE)) {
	                $logLevel = Resque\Worker::LOG_VERBOSE;
	            }
	        }
	
	        $APP_INCLUDE = getenv('APP_INCLUDE');
	        if ($APP_INCLUDE) {
	            if (!file_exists($APP_INCLUDE)) {
	                die('APP_INCLUDE (' . $APP_INCLUDE . ") does not exist.\n");
	            }
	            require_once $APP_INCLUDE;
	        }
	
	        $interval = 5;
	        $INTERVAL = getenv('INTERVAL');
	        if (!empty($INTERVAL)) {
	            $interval = $INTERVAL;
	        }
	
	        $count = 1;
	        $COUNT = getenv('COUNT');
	        if (!empty($COUNT) && $COUNT > 1) {
	            $count = $COUNT;
	        }
	
	        if ($count > 1) {
	            for ($i = 0; $i < $count; ++$i) {
	                $pid = pcntl_fork();
	                if ($pid == -1) {
	                    die("Could not fork worker " . $i . "\n");
	                } // Child, start the worker
	                else {
	                    if (!$pid) {
	                        $worker = new Resque\Worker($this->queues);
	                        $worker->logLevel = $logLevel;
	                        fwrite(STDOUT, '*** Starting worker ' . $worker . "\n");
	                        $worker->work($interval);
	                        break;
	                    }
	                }
	            }
	        } // Start a single worker
	        else {
	            $worker = new Resque\Worker($this->queues);
	            $worker->logLevel = $logLevel;
	
	            $PIDFILE = getenv('PIDFILE');
	            if ($PIDFILE) {
	                file_put_contents($PIDFILE, getmypid()) or
	                die('Could not write PID information to ' . $PIDFILE);
	            }
	
	            fwrite(STDOUT, '*** Starting worker ' . $worker . "\n");
	            $worker->work($interval);
	        }
	    }
	
	    /**
	     * 停止队列
	     */
	    public function stop()
	    {
	        $worker = new Resque\Worker($this->queues);
	        $worker->shutdown();
	    }
	
	    /**
	     * 查看某个任务状态
	     */
	    public function status()
	    {
	        $id = in_array('--id', $this->keys) ? $this->args['--id'] : '';
	        $status = new \Resque\Job\Status($id);
	        if (!$status->isTracking()) {
	            die("Resque is not tracking the status of this job.\n");
	        }
	
	        echo "Tracking status of " . $id . ". Press [break] to stop.\n\n";
	        while (true) {
	            fwrite(STDOUT, "Status of " . $id . " is: " . $status->get() . "\n");
	            sleep(1);
	        }
	    }
	}

### 新增队列配置 ###

在公共`config.php`中新增队列配置，如下

	/* 消息队列配置 */
    'QUEUE' => array(
        'type' => 'redis',
        'host' => '127.0.0.1',
        'port' =>  '6379',
        'prefix' => 'queue',
        'auth' =>  '',
    ),

### 新增队列初始化行为 ###

在`app_init`行为中新增队列初始化的行为，`run`内容为

    public function run()
    {
		// 处理队列配置
	    $config = C('QUEUE');
	    if ($config) {
	        vendor('php-resque.autoload');
	        // 初始化队列服务,使用database(1)
	        \Resque::setBackend(['redis' => $config], 1);
	        // 初始化缓存前缀
	        if(isset($config['prefix']) && !empty($config['prefix']))
	        \Resque\Redis::prefix($config['prefix']);
	    }
	}

到此，整个队列服务基本已配置完成。

接下来就要创建队列执行的任务了

## Jobs ##

### 创建 Jobs ###

目前任务类固定在`Common`模块的`Job`中，命名格式为`XxxxJob.class.php`

	<?php
	namespace Common\Job;
	class XxxxJob
	{
	    public function perform()
	    {
	        $args = $this->args;
	        fwrite(STDOUT, json_encode($args) . PHP_EOL);
	    }
	}

要获取队列中传入的参数值请使用`$this->args`

任务perform方法中抛出的任何异常都会导致任务失败，所以在写任务业务时要小心，并且处理异常情况。

任务也有`setUp`和`tearDown`方法，如果定义了一个`setUp`方法，那么它将在`perform`方法之前调用，如果定义了一个`tearDown`方法，那么它将会在`perform`方法之后调用。

	<?php
	namespace Common\Job;
	class XxxxJob
	{
		public function setUp()
		{
			// ... Set up environment for this job
		}
		
		public function perform()
		{
			// .. Run job
		}
		
		public function tearDown()
		{
			// ... Remove environment for this job
		}
	}

### 添加任务到队列中 ###

在程序控制器的任意方法中引入队列类库时，使用`Resque::enqueue`方法执行入栈，`Resque::enqueue`方法有四个参数，第一个是当前的队列名称，第二个参数为任务类，第三个是传入的参数，第四个表示是否返回工作状态的令牌

	vendor('php-resque.autoload');	// 引入队列类库
    $job = '\\Common\\Job\\XxxxJob'; // 定义任务类
	// 定义参数
    $args = array(
        'time' => time(),
        'array' => array(
            'test' => 'test',
        ),
    );
	// 入栈
    $jobId = \Resque::enqueue('default', $job, $args, true);
    echo "Queued job ".$jobId."\n\n";

如果要查看当前任务的工作状态可以使用如下方法：

	$status = new \Resque\Job\Status($jobId);
	echo $status->get(); // Outputs the status

任务的工作状态值有专门的常量``\Resque\Job\Status``对应类。
具体的对应关系如下：

* `Resque\Job\Status::STATUS_WAITING` - 任务在队列中
* `Resque\Job\Status::STATUS_RUNNING` - 任务正在运行
* `Resque\Job\Status::STATUS_FAILED` - 任务执行失败
* `Resque\Job\Status::STATUS_COMPLETE` - 任务执行完成
* `false` - 无法获取状态 - 检查令牌是否有效?

任务的过期时间为任务完成后的24小时后，也可以定义过期类的`stop()`方法

## 队列任务启动 ##

在命令行中转到项目根目录，执行

    $ php resque start

即可启动服务

启动时也可以加入部分参数：

* `--queue` - 需要执行的队列的名字，可以为空，也可以多个以`,`分割
* `--interval` -在队列中循环的间隔时间，即完成一个任务后的等待时间，默认是5秒
* `--count` - 需要创建的Worker的数量。所有的Worker都具有相同的属性。默认是创建1个Worker
* `--debug` - 设置“1”启用更啰嗦模式，会输出详细的调试信息
* `--pid` - 手动指定PID文件的位置，适用于单Worker运行方式

如：

	$ php resque start --queue=default --pid=/tmp/resque.pid --debug=1

如果要使用守护进程方式启动则需要在最后加入`&`即可

如：

	$ php resque start --queue=default --pid=/tmp/resque.pid --debug=1 &


更多的操作请参考php-resque官方文档。