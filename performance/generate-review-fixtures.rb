require 'json'
gem 'liquid', '= 5.13.0'
require 'liquid'
cases = [
 ['truthiness', '{% if zero %}T{% else %}F{% endif %}{% if text %}T{% else %}F{% endif %}{% if list %}T{% else %}F{% endif %}', {'zero'=>0,'text'=>'','list'=>[]}],
 ['nil shadows outer property', '{% assign product = nil %}{{ product.title }}', {'product'=>{'title'=>'outer'}}],
 ['missing property shadows outer property', '{% assign product = other %}{{ product.title }}', {'product'=>{'title'=>'outer'},'other'=>{'id'=>1}}],
 ['duplicate case matches', '{% case value %}{% when "x" %}A{% when "x" %}B{% endcase %}', {'value'=>'x'}],
 ['array bracket pseudo properties', '{{ items["size"] }}|{{ items["first"] }}|{{ items["last"] }}', {'items'=>['a','b']}],
 ['array dot pseudo properties', '{{ items.size }}|{{ items.first }}|{{ items.last }}', {'items'=>['a','b']}],
 ['default false', '{{ value | default: "fallback", allow_false: true }}', {'value'=>false}],
 ['empty blank', '{% if text == blank %}B{% endif %}{% if list == empty %}E{% endif %}', {'text'=>'  ','list'=>[]}],
 ['decimal arithmetic', '{{ 0.0725 | times: 100 }}|{{ 0.1 | plus: 0.2 }}', {}],
 ['negative integer division', '{{ -5 | divided_by: 2 }}|{{ -5 | modulo: 2 }}', {}],
 ['unicode truncate', '{{ text | truncate: 4 }}', {'text'=>'éééééé'}],
 ['negative slice', '{{ text | slice: -3, 2 }}', {'text'=>'abcdef'}],
 ['loop continue offset', '{% for x in items limit: 2 %}{{x}}{% endfor %}|{% for x in items offset: continue %}{{x}}{% endfor %}', {'items'=>[1,2,3,4]}],
 ['loop break', '{% for x in items %}{{x}}{% if x == 2 %}{% break %}{% endif %}{% endfor %}', {'items'=>[1,2,3]}],
 ['loop continue', '{% for x in items %}{% if x == 2 %}{% continue %}{% endif %}{{x}}{% endfor %}', {'items'=>[1,2,3]}],
 ['whitespace control', ' a {%- assign x = 1 -%} b {{- x -}} c ', {}],
 ['escape apostrophe', '{{ text | escape }}', {'text'=>'<a x="1">\'&'}],
 ['filter boolean conversion', '{{ value | append: "!" }}', {'value'=>false}],
 ['numeric coercion', '{{ value | plus: 1 }}', {'value'=>'12abc'}],
 ['contains string', '{% if text contains "bc" %}Y{% endif %}', {'text'=>'abcd'}],
 ['capture', '{% capture x %}Hi {{ name }}{% endcapture %}{{x}}', {'name'=>'Alice'}]
]
result = cases.map do |name, source, data|
  {'name'=>name,'template'=>source,'data'=>data,'expected'=>Liquid::Template.parse(source,error_mode: :strict).render!(data)}
end
File.write(ARGV.fetch(0), JSON.pretty_generate(result)+"\n")
puts "Generated #{result.length} fixtures using Shopify Liquid #{Liquid::VERSION}"
