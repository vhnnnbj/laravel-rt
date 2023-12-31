## 快速预览
安装laravel5.5 - laravel8之间的版本，然后安装composer包
```shell
## 必须使用composer2版本
composer require windawake/laravel-reset-transaction dev-master
```

首先创建order，storage，account3个mysql数据库实例，3个控制器，3个model，在phpunit.xml增加testsuite Transaction，然后启动web服务器。这些操作只需要执行下面命令全部完成
```shell
php artisan resetTransact:create-examples && php artisan serve --host=0.0.0.0 --port=8000
```
打开另一个terminal，启动端口为8001的web服务器
```shell
php artisan serve --host=0.0.0.0 --port=8001
```
最后运行测试脚本 `
./vendor/bin/phpunit --testsuite=Transaction --filter=ServiceTest
`运行结果如下所示，3个例子测试通过。
```shell
DESKTOP:/web/linux/php/laravel/laravel62# ./vendor/bin/phpunit --testsuite=Transaction --filter=ServiceTest
Time: 219 ms, Memory: 22.00 MB

OK (3 tests, 12 assertions)
```

## 功能特性
1. 开箱即用，不需要重构原有项目的代码，与mysql事务写法一致，简单易用。
2. 两段提交的强一致性事务，高并发下，支持读已提交的事务隔离级别，数据一致性几乎100%。
3. 性能超过seata AT模式，由于事务拆分成多个，变成了几个小事务，压测发现比mysql普通事务更少发生死锁。
4. 支持分布式事务嵌套，与savepoint一致效果。
5. 支持避免不同业务代码并发造成脏数据的问题。
6. 默认支持http协议的服务化接口，想要支持其它协议则需要重写中间件。
7. [支持子服务嵌套分布式事务（突破技术）](#支持子服务嵌套分布式事务（突破技术）)。
8. 支持服务，本地事务和分布式事务混合嵌套
9. 支持超时3次重试，重复请求保证幂等性
10. 几乎支持所有sql语句，可以批量插入，批量更新，批量删除（突破技术）
11. 支持检测xa prepare造成的锁，更加精准地释放xa锁
12. 支持go，java语言（开发中）

对比阿里seata AT模式，有什么优点？请阅读 https://learnku.com/articles/63797
可行性报告，请阅读 https://learnku.com/articles/64923

## 解决了哪些并发场景
- [x] 一个待发货订单，用户同时操作发货和取消订单，只有一个成功
- [x] 积分换取优惠券，只要出现积分不够扣减或者优惠券的库存不够扣减，就会全部失败。

## 原理解析
Reset Transaction，中文名为重置型分布式事务，又命名为RT模式，与seata AT模式都是属于二段提交。跟中国电视剧的【穿越】是同一个意思。
看过《明日边缘》电影就会知道，存档和读档的操作。这个分布式事务组件仿造《明日边缘》电影的原理，每次请求基础服务一开始时读档，然后继续后面的操作，结束时所有操作全部回滚并且存档，最后一步commit把存档全部执行成功。整个过程是遵守两段提交协议，先prepare，最后commit。

以创建一个订单并且扣减一个库存的场景为例子，画了以下流程图。
![](https://cdn.learnku.com/uploads/images/202202/19/46914/9bcNTn58CH.png!large)
右图开启分布式事务RT模式后，比左图多了请求4。请求4所做的事情，都是请求1-3之前做过的东西，又回来原点重新再来，最终提交事务，结束这创建订单的流程。

## 支持子服务嵌套分布式事务（突破技术）
![](https://cdn.learnku.com/uploads/images/202112/30/46914/IzHhjfjHC1.png!large)
世界级的一个难题：A服务commit->B服务rollback->C服务commit->D服务commit sql，这种场景下，ABCD都是不同数据库，如何才能实现让A服务提交了B服务，回滚了C服务和D服务的所有操作呢？

这个问题，seata和go-dtm都没法解决。解决问题的关键点在于C服务和D服务必须要假提交，不能真提交，如果真提交就无力回天了。

实现支持子服务嵌套分布式事务后，带来什么好处呢？可以让A服务成为别人的服务，并且任意嵌套在链路里任何一层。打破了以往的束缚：A服务必须是根服务，A服务若要成为子服务，必须大改代码。用了RT模式的话，A服务不需要修改代码就能成为别人的服务。

## 如何使用

在laravel框架里，把门面DB换成RT就能实现分布式事务。
```php
<?php
use Illuminate\Support\Facades\DB;
use Laravel\ResetTransaction\Facades\RT;
DB::beginTransaction();
...
DB::commit();
#换成
RT::beginTransaction();
...
RT::commit();

#详细的例子
RT::beginTransaction();
(new Client)->put('http://127.0.0.1:8000/api/resetOrder/11', [
	'json' => [
	 	'order_no' => 'aaa',
	],
	'headers' => [
		'rt_request_id' => session_create_id(), //支持幂等
		'rt_transact_id' => RT::getTransactId(), //让订单服务知道，当前是在分布式事务内部
	]
]);
RT::commit();
```
具体例子可以查看composer包里面的`vendor/windawake/laravel-reset-transaction/examples/tests/Transaction/ServiceTest.php`的代码。

## 个人笔记
本人之前写了[laravel快速服务化包](https://learnku.com/articles/61638 "laravel快速服务化包")，但是它没有解决数据一致性的问题。尝试用XA，但是XA只能解决跨数据但是不能解决跨服务的问题。然后我又尝试去研究tcc和seata，难学而且难用，没办法了只能自创分布式事务解决方案。一直以来，我一直以为单单只用mysql是没法解决分布式事务的问题，现在终于明白，还是有办法滴！

![](https://cdn.learnku.com/uploads/images/202202/25/46914/heg3sLvwiG.jpg!large)

希望有更多的朋友相互学习和一起研究分布式事务的知识。
## 相关资源
laravel版本：
https://github.com/windawake/laravel-reset-transaction
https://gitee.com/windawake/laravel-reset-transaction
hyperf版本（包含压测报告）：
https://github.com/windawake/hyperf-reset-transaction
https://gitee.com/windawake/hyperf-reset-transaction
