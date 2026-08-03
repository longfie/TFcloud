# 哔咔漫画插件第三方来源说明

本插件的账号登录、请求签名、用户资料与每日签到协议参考以下项目重新适配：

- [FirmianaMarsili/picacomic-Punch](https://github.com/FirmianaMarsili/picacomic-Punch)，
  参考提交 `5de186ee17ce6c4f66dc9c84069a8902ecdf2149`；
- [FirmianaMarsili/picacomic-api](https://github.com/FirmianaMarsili/picacomic-api)，
  参考提交 `07abb8c4d4ed9079ee5aec301deb05535f38bccd`。

两个上游项目均采用 GNU Lesser General Public License v3.0。许可证全文见同目录
`LICENSE.picacomic`。

相对于上游 C# 项目，本项目进行了以下修改：

- 只保留账号密码登录、Token 校验、个人资料与每日签到；
- 使用 PHP 独立实现请求签名和 API 响应映射，不引入 .NET 运行时；
- 密码、Token 和账号元数据统一进入 Sodium 凭据保险箱；
- 签到前优先复用 Token，失效后才重新执行账号密码登录；
- 签到结果接入统一任务、运行、明细、经验值和等级字段；

哔咔漫画及相关名称、接口和商标属于其权利人。第三方接口可能因版本、线路或风控策略调整，
请勿高频重试