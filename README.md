## setnx
Redis的setnx指令，设置一个键值对，当且仅当键不存在的时候，才能设置成功。
```php
get test_setnx
$-1
setnx test_setnx 10
:1
get test_setnx
$2
10
setnx test_setnx 99
:0
```

## 用法
一般会使用setnx来实现锁的功能，解决资源竞争、缓存风暴等问题。例如，在缓存风暴中，没有锁保护的情况下，缓存失效，会导致短时间内，多个请求透过缓存到达数据库，请求同一份数据，修改同一份缓存；如果使用了锁，可以让获得锁的请求到达数据库，请求数据后回写缓存，后续没有得到锁的就直接读取新的缓存数据，而不用请求数据库了。

## 锁的实现
下面我一个一个坑地踩，一个一个坑地填，最终呈现完整的实现。
#### 版本1
```php
// 上锁
if($redis->setnx('lock_key', 1)){
	echo '成功'.PHP_EOL;
	// 释放锁
	$redis->delete('lock_key');
}else{
	echo '失败'.PHP_EOL;
}
```
初看貌似没有什么问题，但其实有很严重的问题，如果，在setnx成功后，请求挂掉了，或者忘了delete锁，那么'lock_key'这个锁就被永远锁着，出现死锁了，后续的就没法使用了，解决办法，增加个过期时间（版本2.1）？让它一段时间后自动销毁。

#### 版本2.1
```php
// 上锁
if($redis->setnx('lock_key', 1)){
	$redis->expire('lock_key', 1);
	echo '成功'.PHP_EOL;
	// 释放锁
	$redis->delete('lock_key');
}else{
	echo '失败'.PHP_EOL;
}
```
乍看没什么问题，其实问题还是存在，依旧是死锁的问题，只是问题的出现转移了，如果setnx成功，在expire前，请求挂掉，死锁出现。其实是，setnx和expire不是原子操作，那么用redis自带的事务操作会怎样呢？（版本2.2）

#### 版本2.2
```php
$redis->multi();
$redis->setnx('lock_key', 1);
$redis->expire('lock_key', 1);
$redis->exec();
```
用上事务，其实还是存在问题，那就是，请求过多的时候，锁的过期时间一直被更新，上锁那个家伙在手动释放锁之前退出了，就会导致锁一直有效。

其实以上几种情况讨论的都是没有正常释放锁的情况，保证不出现死锁，过期时间确是一个正确思路，至少官方给出的思路就是用过期时间辅助实现。只是实现的方式不一样（版本3）。

#### 版本3
```php
$now = get_millisecond();

$lock = $redis->setnx($lock_key,$lock_timeout);
if($lock or (($now > (float)$redis->get($lock_key)) and $now > (float)$redis->get_set($lock_key,$lock_timeout))) {
	echo '成功'.PHP_EOL;
}else{
	echo '失败'.PHP_EOL;
}
```
上版本使用setnx和get_set来实现。做法和上三个版本有什么不一样呢，setnx保存的value不是一个true or 1，而是过期的绝对时间戳，为什么这么做呢。

来，我们回到死锁的情况，锁没有被释放掉，以后的请求setnx都会失败，这时候会进入第二步判断，判断锁是否超时失效了($now > get($lock_key))，这时候get_set要出场了，看下下面的场景：
```php
A setnx成功，过期时间戳为5（这是绝对时间戳，为了简化阅读）；
A没有delete锁，挂了；
当前时间戳为6($now > get($lock_key)；6 > 5)，那就是A设置的锁过期了；
B请求锁，过期时间戳为10；同时C也请求锁，过期时间戳为9；
假如C请求成功，C get_set 设置成9，返回5，$now == 6,6 > 5,所以C获得锁；
在C请求锁后，B也请求锁（慢一步，命运的安排），B get_set 设置成10，返回9，$now == 6,6 < 9,表示锁有效，已经被别人获得并更新了，B没有获得锁；
```
这里在别人获得锁后，也被更新过期时间，但不像版本2.1那样，谁都会频繁更新过期时间，所以不会出现版本2.1那样锁长期有效。

## 结语
这样的锁，肯定不是什么高大上的方法，但成本低，效果不错，满足大部分需求了，在我司的抽奖系统里很多地方都可以看到，经过线上考验。如果想要高大上的实现，可以考虑google chubby。





参考：[Redis setnx]( https://redis.io/commands/setnx)
博客：[说说Redis的setnx]( https://jiachuhuang.github.io/2017/07/06/%E8%AF%B4%E8%AF%B4Redis%E7%9A%84setnx/)
