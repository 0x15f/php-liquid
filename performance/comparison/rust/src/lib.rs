//! Benchmark bridge, not a supported production PHP extension.
use serde::Deserialize;
use std::cell::RefCell;
use std::collections::BTreeMap;

#[derive(Deserialize)]
struct Request {
    op: String,
    #[serde(default)]
    templates: BTreeMap<String, String>,
    #[serde(default)]
    inputs: Vec<serde_json::Value>,
    #[serde(default)]
    template: String,
    data: Option<serde_json::Value>,
    #[serde(default)]
    index: usize,
    #[serde(default)]
    value: String,
}

struct Engine {
    templates: BTreeMap<String, liquid::Template>,
    inputs: Vec<liquid::Object>,
}
thread_local! { static ENGINE: RefCell<Option<Engine>> = const { RefCell::new(None) }; }

fn call(input: &[u8]) -> Result<String, String> {
    let Request {
        op,
        templates,
        inputs,
        template,
        data,
        index,
        value,
    } = serde_json::from_slice(input).map_err(|e| e.to_string())?;
    match op.as_str() {
        "init" => {
            let inputs = inputs
                .iter()
                .map(|v| liquid::to_object(v).map_err(|e| e.to_string()))
                .collect::<Result<Vec<_>, _>>()?;
            let mut source = liquid::partials::InMemorySource::new();
            for (name, text) in &templates {
                source.add(name.clone(), text.clone());
            }
            let parser = liquid::ParserBuilder::with_stdlib()
                .partials(liquid::partials::EagerCompiler::new(source))
                .build()
                .map_err(|e| e.to_string())?;
            let mut compiled = BTreeMap::new();
            for (name, text) in templates {
                compiled.insert(name, parser.parse(&text).map_err(|e| e.to_string())?);
            }
            ENGINE.with(|state| {
                *state.borrow_mut() = Some(Engine {
                    templates: compiled,
                    inputs,
                })
            });
            Ok("OK".into())
        }
        // Deserializing Liquid's untagged Value enum directly replays nested
        // data through multiple alternatives. Parse JSON once, then use the
        // library's serializer to build Liquid values in one conversion pass.
        "render" => {
            let data =
                liquid::to_object(&data.ok_or("missing data")?).map_err(|e| e.to_string())?;
            ENGINE.with(|state| {
                let state = state.borrow();
                let engine = state.as_ref().ok_or("engine not initialized")?;
                engine
                    .templates
                    .get(&template)
                    .ok_or("unknown template")?
                    .render(&data)
                    .map_err(|e| e.to_string())
            })
        }
        "prepared" => ENGINE.with(|state| {
            let state = state.borrow();
            let engine = state.as_ref().ok_or("engine not initialized")?;
            let input = engine.inputs.get(index).ok_or("unknown prepared context")?;
            engine
                .templates
                .get(&template)
                .ok_or("unknown template")?
                .render(input)
                .map_err(|e| e.to_string())
        }),
        "echo" => Ok(value),
        _ => Err("unknown operation".into()),
    }
}

#[cfg(target_arch = "wasm32")]
#[extism_pdk::plugin_fn]
pub fn dispatch(input: Vec<u8>) -> extism_pdk::FnResult<String> {
    Ok(call(&input).map_err(extism_pdk::Error::msg)?)
}

#[cfg(not(target_arch = "wasm32"))]
#[repr(C)]
pub struct Buffer {
    pub ptr: *mut u8,
    pub len: usize,
}

/// Caller owns the returned allocation and must call liquid_free exactly once.
#[cfg(not(target_arch = "wasm32"))]
#[no_mangle]
pub unsafe extern "C" fn liquid_call(ptr: *const u8, len: usize) -> Buffer {
    let result = std::panic::catch_unwind(|| call(std::slice::from_raw_parts(ptr, len)))
        .unwrap_or_else(|_| Err("native panic".into()))
        .unwrap_or_else(|e| format!("ERROR:{e}"));
    let mut bytes = result.into_bytes().into_boxed_slice();
    let result = Buffer {
        ptr: bytes.as_mut_ptr(),
        len: bytes.len(),
    };
    std::mem::forget(bytes);
    result
}

#[cfg(not(target_arch = "wasm32"))]
#[no_mangle]
pub unsafe extern "C" fn liquid_free(ptr: *mut u8, len: usize) {
    drop(Box::from_raw(std::ptr::slice_from_raw_parts_mut(ptr, len)));
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn repeated_requests_do_not_share_assignments() {
        assert_eq!(
            call(br#"{"op":"init","templates":{"a":"{{ name }}{% assign name = 'changed' %}"}}"#)
                .unwrap(),
            "OK"
        );
        assert_eq!(
            call(br#"{"op":"render","template":"a","data":{"name":"Alice"}}"#).unwrap(),
            "Alice"
        );
        assert_eq!(
            call(br#"{"op":"render","template":"a","data":{"name":"Bob"}}"#).unwrap(),
            "Bob"
        );
    }
}
