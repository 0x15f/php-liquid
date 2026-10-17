<?php

final class NativeLiquidBridge
{
    private FFI $ffi;

    public function __construct(string $path)
    {
        $this->ffi = FFI::cdef('typedef struct { char *ptr; size_t len; } Buffer;
            Buffer liquid_call(const char *ptr, size_t len);
            void liquid_free(char *ptr, size_t len);', $path);
    }

    public function call(string $input): string
    {
        $buffer = $this->ffi->liquid_call($input, strlen($input));
        try {
            $output = FFI::string($buffer->ptr, $buffer->len);
        } finally {
            $this->ffi->liquid_free($buffer->ptr, $buffer->len);
        }
        if (str_starts_with($output, 'ERROR:')) {
            throw new RuntimeException($output);
        }

        return $output;
    }
}

final class WasmLiquidBridge
{
    private FFI $ffi;

    private FFI\CData $plugin;

    public function __construct(string $path)
    {
        $this->ffi = FFI::cdef('typedef struct ExtismPlugin ExtismPlugin;
            ExtismPlugin *extism_plugin_new(const char *wasm, uint64_t wasm_size, void *functions, uint64_t n_functions, _Bool with_wasi, char **errmsg);
            void extism_plugin_new_error_free(char *err);
            void extism_plugin_free(ExtismPlugin *plugin);
            int32_t extism_plugin_call(ExtismPlugin *plugin, const char *name, const char *data, uint64_t len);
            const char *extism_plugin_error(ExtismPlugin *plugin);
            uint64_t extism_plugin_output_length(ExtismPlugin *plugin);
            const unsigned char *extism_plugin_output_data(ExtismPlugin *plugin);
            const char *extism_version(void);', '/usr/local/lib/libextism.so');
        $wasm = file_get_contents($path);
        $error = $this->ffi->new('char *');
        $plugin = $this->ffi->extism_plugin_new($wasm, strlen($wasm), null, 0, false, FFI::addr($error));
        if ($plugin === null || FFI::isNull($plugin)) {
            $message = FFI::string($error);
            $this->ffi->extism_plugin_new_error_free($error);
            throw new RuntimeException($message);
        }
        $this->plugin = $plugin;
    }

    public function version(): string
    {
        $value = $this->ffi->extism_version();

        return is_string($value) ? $value : FFI::string($value);
    }

    public function call(string $input): string
    {
        if ($this->ffi->extism_plugin_call($this->plugin, 'dispatch', $input, strlen($input)) !== 0) {
            $message = $this->ffi->extism_plugin_error($this->plugin);
            throw new RuntimeException(is_string($message) ? $message : FFI::string($message));
        }
        $length = $this->ffi->extism_plugin_output_length($this->plugin);

        return $length === 0 ? '' : FFI::string(FFI::cast('char *', $this->ffi->extism_plugin_output_data($this->plugin)), $length);
    }

    public function __destruct()
    {
        if (isset($this->plugin)) {
            $this->ffi->extism_plugin_free($this->plugin);
        }
    }
}
