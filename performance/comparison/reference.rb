require 'json'
require 'digest'
gem 'liquid', '= 5.13.0'
require 'liquid'

fixtures = JSON.parse(File.read(ARGV.fetch(0)))
filesystem = Class.new do
  def initialize(templates) = @templates = templates
  def read_template_file(name) = @templates.fetch(name)
end.new(fixtures.fetch('templates'))

templates = fixtures.fetch('templates').transform_values do |source|
  Liquid::Template.parse(source, error_mode: :strict)
end
hashes = fixtures.fetch('workloads').transform_values do |requests|
  requests.map do |request|
    html = templates.fetch(request.fetch('template')).render!(
      request.fetch('data'), strict_variables: true, strict_filters: true,
      registers: { file_system: filesystem }
    )
    Digest::SHA256.hexdigest(html)
  end
end
File.write(ARGV.fetch(1), JSON.pretty_generate(hashes) + "\n")
puts "Validated #{hashes.values.sum(&:length)} HTML contexts with Shopify Liquid #{Liquid::VERSION}"
