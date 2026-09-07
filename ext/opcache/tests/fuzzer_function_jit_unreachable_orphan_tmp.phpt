--TEST--
Block pass must not orphan a temporary whose only consumer is in a removed unreachable block
--EXTENSIONS--
opcache
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.jit=disable
--FILE--
<?php
class D {
    public function __construct(public string $name) {}
    public function __clone() { $this->name .= '*'; }
    public function __destruct() { echo "destruct {$this->name}\n"; }
}

function run(string $name, callable $test) {
    try {
        $test();
    } catch (\Throwable $e) {
        echo $name, ': ', $e::class, "\n";
    }
}

/* The single match arm is constant folded away, so everything after MATCH_ERROR becomes
 * unreachable -- including the ZEND_POW that consumes the clone. The block pass has to
 * keep a FREE for it, otherwise the clone has no live range and survives until shutdown
 * instead of being destroyed while the UnhandledMatchError unwinds. */
function one($o) {
    (clone $o) ** match (1) { 2 => 3 };
}

/* Two temporaries orphaned by the same unreachable block. */
function two($a, $b) {
    (clone $a) ** ((clone $b) ** match (1) { 2 => 3 });
}

/* Here the orphaned live range is a rope. */
function rope($o) {
    return "x{$o->name}y" . match (1) { 2 => 3 };
}

/* ZEND_BIND_LEXICAL stays behind as the only remaining use of the closure temporary.
 * keeps_op1_alive() asserts that this never happens, so a debug build used to abort
 * while recomputing the live ranges. */
function lexical() {
    $u = 1;
    (function () use ($u) {}) ** match (1) { 2 => 3 };
}

$a = new D('a');
run('one', fn() => one($a));
unset($a);

$b = new D('b');
$c = new D('c');
run('two', fn() => two($b, $c));
unset($b, $c);

$d = new D('d');
run('rope', fn() => rope($d));
unset($d);

run('lexical', lexical(...));
echo "done\n";
?>
--EXPECT--
destruct a*
one: UnhandledMatchError
destruct a
destruct b*
destruct c*
two: UnhandledMatchError
destruct b
destruct c
rope: UnhandledMatchError
destruct d
lexical: UnhandledMatchError
done
