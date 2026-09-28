import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url), {chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/trip_completion_render.php')],{encoding:'utf8'});
const token='a'.repeat(64), chunks=[];
let initCount=0, finalCount=0, finalBody='';
const json=(res,data)=>{res.setHeader('Content-Type','application/json');res.end(JSON.stringify(data));};
const server=http.createServer((req,res)=>{
  if(req.url==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);}
  if(req.url?.endsWith('/review')&&req.method==='GET')return json(res,{ok:true,status:'work',version:0,
    problems:[],snapshot_hash:'test-snapshot',sender_ready:true,sender_email:'manager@example.test',recipient_count:1});
  if(req.url?.endsWith('/package-stage')&&req.method==='POST'){
    const offset=req.headers['x-planex-offset'];
    const buffers=[];req.on('data',data=>buffers.push(data));req.on('end',()=>{
      if(offset===undefined){initCount++;return json(res,{ok:true,upload_token:token});}
      const size=Buffer.concat(buffers).length;
      chunks.push({offset:Number(offset),size,token:req.headers['x-planex-upload']});
      json(res,{ok:true,size:Number(offset)+size});
    });return;
  }
  if(req.url?.endsWith('/review')&&req.method==='POST'){
    const buffers=[];req.on('data',data=>buffers.push(data));req.on('end',()=>{
      finalCount++;finalBody=Buffer.concat(buffers).toString('utf8');
      json(res,{ok:true,mail_status:'accepted',status:'review',version:1});
    });return;
  }
  const file=path.resolve(root,'public','.'+req.url.split('?')[0]);
  if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}
  res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':'application/octet-stream');
  res.end(fs.readFileSync(file));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
try{
  const page=await browser.newPage({viewport:{width:1440,height:900}});
  await page.goto(`http://127.0.0.1:${server.address().port}/`);
  await page.evaluate(()=>{window.TripCompletionReport.buildPackage=async()=>({name:'Рейс_244.zip',bytes:new Uint8Array(17*1024*1024+17)});});
  await page.locator('.logistics-trip summary').first().click();
  await page.locator('.logistics-trip [data-review-form] button').first().click();
  await page.locator('.lab-review-dialog').waitFor({state:'visible'});
  assert.match(await page.locator('.lab-review-mail').innerText(),/таблицей заявок и ссылкой на комплект/);
  await page.locator('.lab-review-confirm').click();
  await page.waitForFunction(()=>document.querySelector('.lab-review-dialog')?.open===false);
  assert.equal(initCount,1,'Package staging did not start');
  assert.deepEqual(chunks.map(chunk=>chunk.offset),[0,4*1024*1024,8*1024*1024,12*1024*1024,16*1024*1024],'Chunks were not sent sequentially');
  assert.equal(chunks.reduce((sum,chunk)=>sum+chunk.size,0),17*1024*1024+17,'Some package bytes were lost');
  assert.equal(chunks.every(chunk=>chunk.token===token),true,'Staging token was omitted');
  assert.equal(finalCount,1,'Review was not submitted');
  assert.match(finalBody,/name="package_token"[\s\S]*aaaaaaaa/,'Final submission has no package token');
  assert.doesNotMatch(finalBody,/filename="Рейс_244\.zip"/,'Binary ZIP was attached to the final mail request');
  console.log('TRIP_REVIEW_LINK_BROWSER=PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
