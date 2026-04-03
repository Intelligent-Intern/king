#!/usr/bin/env ruby

require "yaml"

if ARGV.length != 1
    STDERR.puts "Usage: validate-yaml.rb <yaml-path>"
    exit 1
end

path = ARGV[0]

unless File.file?(path) && File.readable?(path)
    STDERR.puts "Error: YAML file '#{path}' does not exist or is not readable."
    exit 1
end

begin
    YAML.safe_load(
        File.read(path),
        permitted_classes: [],
        permitted_symbols: [],
        aliases: false
    )
rescue StandardError => error
    STDERR.puts "Error: YAML validation failed for #{path}: #{error.class}: #{error.message}"
    exit 1
end
