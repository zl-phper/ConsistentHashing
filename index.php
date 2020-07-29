<?php
/**
 * 当数据量大的时候我们会给 数据库 或者redis 分库分表，比如我们的订单详细数据存在redis里面，然后我们有10台 redis 缓存服务器，
 * 一般情况下我们可能会对订单号取余然后去相应的服务器上拿数据这样我们可以解决 不用每一台服务器去查找数据 但是这样就会出现一种问题。
 * 如果我们的redis服务器数量变了 比如有一台坏了或者业务加大 十台服务器不够用了，我们需要对应的去加大或者减少服务器的数量，如果用这种方式，
 * 那么每一个订单的数据落在缓存服务器上的位置都会发生变化，所有的订单都拿不到数据，那么就会发生cache击穿，请求都会打到DB 上。所以为了避免这种事情发生，
 * 就有了一致性hash算法。
 *
 * 一致性hash的方式也是取模，只不过上面的方法是对服务器的数量进行取模，但是一直行hash是对2的32次方取模。简单的来说就是把2的32次方这个数取模，简单的来说就是把
 * 从0 到2 的32次方行成一个圆环，起点位置为0 然后 顺时针递增，知道2的32次方，形成一个圆环。
 * 接下来 我们就可以把我们的服务器的ip地址或者服务器名字 求出hash值，然后标注在环的位置上， 比如 0点有一台机器  3点有一台机器  6点 一台机器 和九点一台机器，
 * 然后这时候有一个key  我们可以计算他的hash值，然后去环上顺时针的走下去，找到的第一台服务器，就是他cache落地的地址。
 * 如果这个时候 我们的三点钟服务器挂掉了 影响的只是这台服务器到0点钟之间的数据（其他两台服务器上的数据不会收到影响）  同理 如果我们新增加了一台服务器，那么影响的也
 * 只是他到逆时针到上一台的数据。
 *  还有一种情况 比如说我们的服务器比较少 只有两台的情况下， 比如第一台在圆环的一点钟方向，第二台在三点钟方向，根据上面说的 key 会命中在 环顺时针遇到的第一台机器上，
 * 这时候也就是说大部分的key都落在第一台机器上，我们也可以做一些虚拟节点 比如   123456 落在 第一台 789 10 11 12 落到第二台机器上
 *
 *
 */
class ConsistentHashing
{
    /**
     * 设置节点的数量
     * 节点数量越多，一致性的hash平衡性就越好
     *
     * @var int
     */
    protected $replicate = 0;

    /**
     * 保存每个节点的缓存位置
     *
     * @var array
     */
    protected $nodes = [];

    /**
     * 每个节点的位置
     *
     * @var array
     */
    public $cachePosition = [];

    /**
     *
     * @param int $replicate
     */
    public function __construct(int $replicate = 3)
    {
        $this->replicate = $replicate;
    }

    /**
     * 返回32位整数
     *
     * @param string $key
     *
     * @return int
     */
    public static function hash(string $key): int
    {
        return (int)sprintf("%u", crc32($key));
    }

    /**
     * 添加一个节点
     *
     * @param string $node
     *
     * @return bool
     */
    public function addNode(string $node): bool
    {
        if (strlen($node) < 1) {
            return false;
        }

        for ($i = 1; $i <= $this->replicate; $i++) {
            $positionKey = self::hash($node . $i);
            $this->nodes[$node][] = $positionKey;
            $this->cachePosition[$positionKey] = $node;
        }

        return true;
    }

    /**
     * 删除一个节点
     *
     * @param string $node
     *
     * @return bool
     */
    public function delNode(string $node): bool
    {
        if (strlen($node) < 1) {
            return false;
        }

        $failedPosition = $this->nodes[$node];
        unset($this->nodes[$node]);

        foreach ($failedPosition as $item) {
            unset($this->cachePosition[$item]);
        }

        return true;
    }

    /**
     * 查找key 的节点
     *
     * @param string $key
     *
     * @return bool|string
     */
    public function lookUp(string $key)
    {
        if (strlen($key) < 1) {
            return false;
        }

        $key = self::hash($key);
        ksort($this->cachePosition);

        foreach ($this->cachePosition as $position => $node) {
            if ($key <= $position) {
                return $node;
            }
        }

        return current($this->cachePosition);
    }
}


//Examples of use :

$server = new ConsistentHashing(1000);
$server->addNode("192.168.1.1");
$server->addNode("192.168.1.2");
$server->addNode("192.168.1.3");
$server->addNode("192.168.1.13");


echo "Save on:" . $server->lookUp('kkkkasdasdasdasds') . PHP_EOL;

echo "Save on:" . $server->lookUp('bbbbkkkkasdasdasdasds') . PHP_EOL;

echo "Save on:" . $server->lookUp('ssssssbbbbbbbbbb') . PHP_EOL;
