<?php
// Generates a Psalm stub for ext-rdkafka from the loaded extension (classes, methods, constants).
$ext = new ReflectionExtension('rdkafka');
$t = static function (?ReflectionType $type): string {
    if ($type === null) return '';
    // fully qualify class names: the stub declares several namespaces
    $one = static fn(ReflectionNamedType $n): string => $n->isBuiltin() ? $n->getName() : '\\' . ltrim($n->getName(), '\\');
    if ($type instanceof ReflectionNamedType) {
        return ($type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null' ? '?' : '') . $one($type);
    }
    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map(static fn($n) => $n instanceof ReflectionNamedType ? $one($n) : (string) $n, $type->getTypes()));
    }
    return (string) $type;
};
$byNs = [];
foreach ($ext->getClasses() as $c) {
    $byNs[$c->getNamespaceName()][] = $c;
}
$out = "<?php\n\n// Psalm stub for ext-rdkafka " . $ext->getVersion() . ", generated from the loaded extension by Reflection.\n// Regenerate: php scripts/ci/rdkafka-stub.php > stubs/rdkafka.phpstub\n\n";
$out .= "namespace {\n";
foreach ($ext->getConstants() as $k => $v) {
    $out .= "    const $k = " . var_export($v, true) . ";\n";
}
foreach ($ext->getFunctions() as $f) {
    $params = [];
    foreach ($f->getParameters() as $p) {
        $params[] = trim($t($p->getType()) . ' ' . ($p->isVariadic() ? '...' : '') . '$' . $p->getName() . ($p->isOptional() && !$p->isVariadic() ? ' = null' : ''));
    }
    $rt = $t($f->getReturnType());
    $out .= "    function {$f->getName()}(" . implode(', ', $params) . ")" . ($rt !== '' ? ": $rt" : '') . " {}\n";
}
$out .= "}\n";
ksort($byNs);
foreach ($byNs as $ns => $classes) {
    $out .= "\nnamespace $ns {\n";
    foreach ($classes as $c) {
        $kind = $c->isInterface() ? 'interface' : (($c->isFinal() ? 'final ' : '') . ($c->isAbstract() ? 'abstract ' : '') . 'class');
        $ext2 = ($p = $c->getParentClass()) ? ' extends \\' . $p->getName() : '';
        $impl = array_diff($c->getInterfaceNames(), $p ? $p->getInterfaceNames() : []);
        $impl = array_filter($impl, static fn($i) => !in_array($i, ['Stringable'], true));
        $out .= "    $kind {$c->getShortName()}$ext2" . ($impl ? ' implements \\' . implode(', \\', $impl) : '') . "\n    {\n";
        foreach ($c->getReflectionConstants() as $k) {
            if ($k->getDeclaringClass()->getName() === $c->getName()) $out .= "        public const {$k->getName()} = " . var_export($k->getValue(), true) . ";\n";
        }
        foreach ($c->getProperties() as $prop) {
            if ($prop->getDeclaringClass()->getName() !== $c->getName() || !$prop->isPublic()) continue;
            $vis = $prop->isPublic() ? 'public' : ($prop->isProtected() ? 'protected' : 'private');
            $pt = $t($prop->getType());
            $out .= "        $vis " . ($prop->isStatic() ? 'static ' : '') . ($pt !== '' ? "$pt " : '') . '$' . $prop->getName() . ";\n";
        }
        foreach ($c->getMethods() as $m) {
            if ($m->getDeclaringClass()->getName() !== $c->getName()) continue;
            $params = [];
            foreach ($m->getParameters() as $p) {
                $pt = $t($p->getType());
                $def = '';
                if ($p->isOptional() && !$p->isVariadic()) {
                    $def = $p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : ' = null';
                    if ($def === ' = null' && $pt !== '' && !str_starts_with($pt, '?') && !str_contains($pt, 'null') && $pt !== 'mixed') $pt = '?' . $pt;
                }
                $params[] = trim($pt . ' ' . ($p->isPassedByReference() ? '&' : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName() . $def);
            }
            $rt = $t($m->getReturnType() ?? $m->getTentativeReturnType());
            $mods = ($m->isPublic() ? 'public' : ($m->isProtected() ? 'protected' : 'private')) . ($m->isStatic() ? ' static' : '') . ($m->isFinal() && !$c->isFinal() ? ' final' : '');
            $body = $c->isInterface() || $m->isAbstract() ? ';' : ' {}';
            if ($m->isAbstract() && !$c->isInterface()) $mods = 'abstract ' . $mods;
            $out .= "        $mods function {$m->getName()}(" . implode(', ', $params) . ")" . ($rt !== '' && $m->getName() !== '__construct' ? ": $rt" : '') . "$body\n";
        }
        $out .= "    }\n";
    }
    $out .= "}\n";
}
echo $out;
