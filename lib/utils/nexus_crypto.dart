import 'package:encrypt/encrypt.dart' as encrypt;
import 'dart:convert';
import 'package:crypto/crypto.dart';
import 'dart:typed_data';

class NexusCrypto {
  static final _secretKey = 'SecretKeyWord';
  static final _secretIv = 'SecretIV@123GKrQp';

  static String encryptor(String plainText) {
    // SHA-256 hash, 32 bytes binarios
    final keyBytes = sha256.convert(utf8.encode(_secretKey)).bytes;
    final key = encrypt.Key(Uint8List.fromList(keyBytes));
    // IV: primeros 16 bytes binarios del hash SHA-256 del IV
    final ivBytes = sha256.convert(utf8.encode(_secretIv)).bytes.sublist(0, 16);
    final iv = encrypt.IV(Uint8List.fromList(ivBytes));
    final encrypter = encrypt.Encrypter(encrypt.AES(key, mode: encrypt.AESMode.cbc));
    final encrypted = encrypter.encrypt(plainText, iv: iv);
    return encrypted.base64; // Solo una vez base64
  }
}
